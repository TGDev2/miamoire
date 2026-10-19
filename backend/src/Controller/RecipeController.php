<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Household;
use App\Entity\SavedRecipe;
use App\Enum\Equipment;
use App\Enum\FoodGroup;
use App\Enum\MealTemperature;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\MealPlanEntryRepository;
use App\Repository\SavedRecipeRepository;
use App\Service\Ai\Import\RecipeImportService;
use App\Service\Ai\PriorityChoice;
use App\Service\Ai\RecipeConfirmationService;
use App\Service\Ai\RecipeNormalizer;
use App\Service\Ai\RecipePlanService;
use App\Service\Ai\RecipeRequest;
use App\Service\Ai\RecipeSuggestionService;
use App\Service\Ai\Safety\DietaryGuardFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/recipes')]
class RecipeController extends AbstractApiController
{
    /** A whole conversation fits; a pasted book does not. */
    private const MAX_IMPORTED_TEXT = 100_000;

    /** Génère des propositions. Aucune écriture en stock à cette étape. */
    #[Route('/suggest', methods: ['POST'])]
    public function suggest(
        Request $request,
        RequestContext $context,
        RecipeSuggestionService $service,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $result = $service->suggest($household, $this->recipeRequest(JsonPayload::fromRequest($request), $household));

        // Le plan de chaque recette est un objet de service, pas encore une forme
        // JSON : `ApiPresenter` reste la seule source de vérité du contrat API.
        $result['recipes'] = array_map(
            static fn (array $recipe): array => [...$recipe, 'plan' => $presenter->recipePlan($recipe['plan'])],
            $result['recipes'],
        );

        return $this->json($result);
    }

    /**
     * La même demande que `suggest`, rédigée pour un autre assistant : à copier
     * à la main quand le générateur ne répond pas. Ne sollicite aucun
     * générateur, n'écrit rien.
     */
    #[Route('/prompt', methods: ['POST'])]
    public function prompt(
        Request $request,
        RequestContext $context,
        RecipeSuggestionService $service,
    ): JsonResponse {
        $household = $context->household();

        return $this->json([
            'prompt' => $service->manualPrompt($household, $this->recipeRequest(JsonPayload::fromRequest($request), $household)),
        ]);
    }

    /**
     * One recipe written out for another assistant to change; the changed
     * version comes back through `import`. Calls no generator, writes nothing.
     */
    #[Route('/modification-prompt', methods: ['POST'])]
    public function modificationPrompt(
        Request $request,
        RequestContext $context,
        RecipeSuggestionService $service,
        RecipeNormalizer $normalizer,
        SavedRecipeRepository $savedRecipes,
    ): JsonResponse {
        $household = $context->household();
        $recipe = $this->recipeDocument(JsonPayload::fromRequest($request), $household, $normalizer, $savedRecipes);

        return $this->json(['prompt' => $service->modificationPrompt($household, $recipe)]);
    }

    /**
     * Reads the recipes pasted back from another assistant. Writes nothing:
     * the user keeps the one they chose through `POST /saved`, like any other
     * recipe.
     */
    #[Route('/import', methods: ['POST'])]
    public function import(
        Request $request,
        RequestContext $context,
        RecipeImportService $service,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $text = JsonPayload::fromRequest($request)->requiredString('text');

        if (mb_strlen($text) > self::MAX_IMPORTED_TEXT) {
            throw new BadRequestHttpException('Ce texte est trop long pour être importé : copiez seulement la ou les recettes choisies.');
        }

        $result = $service->read($household, $text);

        return $this->json([
            'recipes' => array_map(
                static fn (array $imported): array => [
                    'recipe' => [...$imported['recipe'], 'plan' => $presenter->recipePlan($imported['plan'])],
                    'notices' => $imported['notices'],
                ],
                $result['recipes'],
            ),
            'rejected' => $result['rejected'],
        ]);
    }

    /** Le corps commun à `suggest` et `prompt` : une seule lecture, pour que l'export ne dérive pas. */
    private function recipeRequest(JsonPayload $payload, Household $household): RecipeRequest
    {
        // Les exclusions du foyer s'appliquent par défaut, la requête peut les compléter.
        $exclusions = array_values(array_unique(array_merge(
            $household->getDietaryExclusionLabels(),
            $payload->stringList('exclusions'),
        )));

        return new RecipeRequest(
            people: max(1, min(20, $payload->int('people', 2) ?? 2)),
            mealType: $payload->string('mealType'),
            mealTemperature: $payload->enum('mealTemperature', MealTemperature::class),
            maxMinutes: $payload->int('maxMinutes'),
            // Réduits aux équipements connus : une chaîne inconnue était jusqu'ici
            // recopiée telle quelle dans le prompt, autorisant le modèle à se
            // servir d'un appareil qui n'existe pas.
            equipment: Equipment::fromValues($payload->stringList('equipment')),
            exclusions: $exclusions,
            allowExtraIngredients: $payload->bool('allowExtraIngredients'),
            // Facultatif : demander des recettes sans rien imposer reste le cas
            // courant. Au plus 3 — le premier est une contrainte, les autres
            // de simples préférences (voir RecipeRequest).
            priorities: array_slice(PriorityChoice::listFrom($payload), 0, 3),
            // Absent dans les applications antérieures : aucune contrainte de
            // composition, comme avant.
            foodGroups: FoodGroup::fromValues($payload->stringList('foodGroups')),
        );
    }

    /**
     * Applique la déduction après confirmation explicite de l'utilisateur.
     *
     * `operationKey` est obligatoire : c'est elle qui rend le geste rejouable sans
     * effet supplémentaire. Une réponse réseau perdue puis une nouvelle tentative
     * portent la même clé, et ne déduisent donc qu'une fois.
     */
    #[Route('/confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        RequestContext $context,
        RecipeConfirmationService $service,
        RecipeNormalizer $normalizer,
        SavedRecipeRepository $savedRecipes,
        MealPlanEntryRepository $mealPlanEntries,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $household = $context->household();
        $recipe = $this->recipeDocument($payload, $household, $normalizer, $savedRecipes);

        // Résolu avec tolérance : un id périmé, étranger, ou déjà effacé ne doit
        // jamais empêcher la confirmation réelle — seul le nettoyage du plan
        // de repas en pâtit, pas la consommation.
        $mealPlanEntryId = $payload->string('mealPlanEntryId');
        $mealPlanEntry = null !== $mealPlanEntryId ? $mealPlanEntries->findOwned($household, $mealPlanEntryId) : null;

        $result = $service->confirm(
            $household,
            $context->requireMember(),
            $payload->objectList('stockConsumptionProposal'),
            $payload->string('recipeName'),
            $payload->requiredString('operationKey'),
            $recipe,
            $mealPlanEntry,
        );

        return $this->json($result);
    }

    /**
     * Le plan de préparation actuel d'une recette enregistrée.
     *
     * Recalculé à chaque appel — stock, exclusions et réassociation des aliments
     * renommés — plutôt que de rejouer le statut figé dans le JSON enregistré.
     */
    #[Route('/saved/{id}/plan', methods: ['GET'])]
    public function savedRecipePlan(
        string $id,
        RequestContext $context,
        SavedRecipeRepository $savedRecipes,
        RecipePlanService $planner,
        DietaryGuardFactory $guards,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $saved = $this->ownedRecipe($savedRecipes, $context, $id);

        $plan = $planner->plan(
            $guards->for($household),
            $household->getStrictExclusionLabels(),
            $household,
            $saved->getRecipe(),
        );

        return $this->json($presenter->recipePlan($plan));
    }

    /**
     * The recipe a request is about: the one saved under `savedRecipeId`, or
     * the one sent under `recipe`, normalized, if it has not been saved — a
     * fresh proposal or a meal-plan snapshot.
     *
     * For a confirmation, the proposal sent back by the client is not trusted
     * alone: this document is what `RecipeConfirmationService` checks again
     * (exclusions, reassociation, stock) right before writing anything.
     *
     * @return array<string, mixed>
     */
    private function recipeDocument(
        JsonPayload $payload,
        Household $household,
        RecipeNormalizer $normalizer,
        SavedRecipeRepository $savedRecipes,
    ): array {
        $savedRecipeId = $payload->string('savedRecipeId');

        if (null !== $savedRecipeId) {
            $saved = $savedRecipes->findOwned($household, $savedRecipeId)
                ?? throw new NotFoundHttpException('Cette recette enregistrée n\'existe plus.');

            return $saved->getRecipe();
        }

        $recipe = $payload->object('recipe');

        if ([] === $recipe) {
            throw new BadRequestHttpException('La recette est requise (`recipe` ou `savedRecipeId`).');
        }

        return $normalizer->normalize($recipe);
    }

    /** Les recettes que le foyer a gardées, la plus récente en tête. */
    #[Route('/saved', methods: ['GET'])]
    public function savedRecipes(
        RequestContext $context,
        SavedRecipeRepository $savedRecipes,
        ApiPresenter $presenter,
    ): JsonResponse {
        return $this->json([
            'recipes' => array_map(
                static fn (SavedRecipe $saved) => $presenter->savedRecipe($saved),
                $savedRecipes->findForHousehold($context->household()),
            ),
        ]);
    }

    /**
     * Conserve une proposition.
     *
     * Enregistrer deux fois la même recette ne crée pas de doublon : l'empreinte
     * du contenu porte un index unique par foyer, et une seconde tentative renvoie
     * simplement l'enregistrement existant. C'est ce qui rend le geste sûr depuis
     * un téléphone — double appui, réseau perdu puis nouvelle tentative.
     */
    #[Route('/saved', methods: ['POST'])]
    public function saveRecipe(
        Request $request,
        RequestContext $context,
        RecipeNormalizer $normalizer,
        SavedRecipeRepository $savedRecipes,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $payload = JsonPayload::fromRequest($request);

        $recipe = $normalizer->normalize($payload->object('recipe'));
        $fingerprint = $normalizer->fingerprint($recipe);

        $existing = $savedRecipes->findOneByFingerprint($household, $fingerprint);

        if (null !== $existing) {
            return $this->json($presenter->savedRecipe($existing));
        }

        $saved = new SavedRecipe(
            $household,
            (string) $recipe['recipeName'],
            $recipe,
            $fingerprint,
            RecipeNormalizer::CURRENT_SCHEMA_VERSION,
            $context->member(),
        );

        $entityManager->persist($saved);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux téléphones ont enregistré la même recette au même instant :
            // l'index a tranché, et le perdant relit ce que l'autre a écrit.
            return $this->json($presenter->savedRecipe(
                $savedRecipes->findOneByFingerprint($household, $fingerprint)
                    ?? throw new NotFoundHttpException('Recette introuvable après enregistrement.'),
            ));
        }

        return $this->json($presenter->savedRecipe($saved), Response::HTTP_CREATED);
    }

    #[Route('/saved/{id}', methods: ['GET'])]
    public function savedRecipe(
        string $id,
        RequestContext $context,
        SavedRecipeRepository $savedRecipes,
        ApiPresenter $presenter,
    ): JsonResponse {
        return $this->json($presenter->savedRecipe($this->ownedRecipe($savedRecipes, $context, $id)));
    }

    /**
     * Retire une recette des enregistrées.
     *
     * Ici la suppression est bien physique, contrairement aux lots : une recette
     * ne porte aucun historique de stock — les mouvements qu'elle a provoqués
     * appartiennent à l'opération, pas à elle. Archiver n'aurait rien conservé
     * que quelqu'un puisse consulter un jour.
     */
    #[Route('/saved/{id}', methods: ['DELETE'])]
    public function deleteSavedRecipe(
        string $id,
        RequestContext $context,
        SavedRecipeRepository $savedRecipes,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $entityManager->remove($this->ownedRecipe($savedRecipes, $context, $id));
        $entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function ownedRecipe(
        SavedRecipeRepository $savedRecipes,
        RequestContext $context,
        string $id,
    ): SavedRecipe {
        return $savedRecipes->findOwned($context->household(), $id)
            ?? throw new NotFoundHttpException('Cette recette enregistrée n\'existe plus.');
    }
}
