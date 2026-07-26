<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SavedRecipe;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\SavedRecipeRepository;
use App\Service\Ai\RecipeConfirmationService;
use App\Service\Ai\RecipeNormalizer;
use App\Service\Ai\RecipeRequest;
use App\Service\Ai\RecipeSuggestionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/recipes')]
class RecipeController extends AbstractApiController
{
    /** Génère des propositions. Aucune écriture en stock à cette étape. */
    #[Route('/suggest', methods: ['POST'])]
    public function suggest(
        Request $request,
        RequestContext $context,
        RecipeSuggestionService $service,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $household = $context->household();

        // Les exclusions du foyer s'appliquent par défaut, la requête peut les compléter.
        $exclusions = array_values(array_unique(array_merge(
            $household->getDietaryExclusions(),
            $payload->stringList('exclusions'),
        )));

        $result = $service->suggest($household, new RecipeRequest(
            people: max(1, min(20, $payload->int('people', 2) ?? 2)),
            mealType: $payload->string('mealType'),
            maxMinutes: $payload->int('maxMinutes'),
            equipment: $payload->stringList('equipment'),
            exclusions: $exclusions,
            allowExtraIngredients: $payload->bool('allowExtraIngredients'),
            // Facultatif : demander des recettes sans rien imposer reste le cas courant.
            priorityKey: $payload->string('priorityKey'),
        ));

        return $this->json($result);
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
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $result = $service->confirm(
            $context->household(),
            $context->requireMember(),
            $payload->objectList('stockConsumptionProposal'),
            $payload->string('recipeName'),
            $payload->requiredString('operationKey'),
        );

        return $this->json($result);
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
