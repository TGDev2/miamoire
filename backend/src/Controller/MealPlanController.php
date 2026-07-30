<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MealPlanEntry;
use App\Enum\MealSlot;
use App\Exception\StockException;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\MealPlanEntryRepository;
use App\Repository\SavedRecipeRepository;
use App\Service\Ai\RecipeNormalizer;
use App\Service\MealPlan\MealPlanEntryView;
use App\Service\MealPlan\MealPlanOverview;
use App\Service\MealPlan\MealPlanService;
use App\Service\MealPlan\MealPlanShortfallLine;
use App\Service\Shopping\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le plan de repas court — pas de clé d'opération : comme la liste de
 * courses, ce sont des écritures bon marché et réversibles, qui ne touchent
 * jamais au stock (voir `MealPlanService` pour la réservation, purement en
 * lecture, et `RecipeConfirmationService` pour son remplacement par une
 * consommation réelle).
 */
#[Route('/api/meal-plan')]
class MealPlanController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function overview(
        RequestContext $context,
        MealPlanService $mealPlan,
        ApiPresenter $presenter,
    ): JsonResponse {
        $overview = $mealPlan->overview($context->household());

        return $this->json($this->presentOverview($overview, $presenter));
    }

    /**
     * `{date, mealSlot}` et exactement un de `savedRecipeId`, `recipe`, `label`.
     * Le plan reste court : un 5ᵉ repas actif est refusé plutôt que d'ouvrir
     * la porte à un calendrier complet.
     */
    #[Route('/entries', methods: ['POST'])]
    public function createEntry(
        Request $request,
        RequestContext $context,
        MealPlanEntryRepository $mealPlanEntries,
        SavedRecipeRepository $savedRecipes,
        RecipeNormalizer $normalizer,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();

        if ($mealPlanEntries->countForHousehold($household) >= MealPlanService::MAX_ACTIVE_ENTRIES) {
            throw new StockException(sprintf(
                'Votre plan est complet (%d repas). Retirez-en un avant d\'en ajouter un autre.',
                MealPlanService::MAX_ACTIVE_ENTRIES,
            ));
        }

        $payload = JsonPayload::fromRequest($request);
        $date = $payload->date('date') ?? throw new StockException('Indiquez la date de ce repas.');
        $slot = $payload->requiredEnum('mealSlot', MealSlot::class);
        $addedBy = $context->member();

        $savedRecipeId = $payload->string('savedRecipeId');
        $recipe = $payload->object('recipe');
        $label = $payload->string('label');

        $providedCount = (null !== $savedRecipeId ? 1 : 0) + ([] !== $recipe ? 1 : 0) + (null !== $label ? 1 : 0);
        if (1 !== $providedCount) {
            throw new StockException('Choisissez exactement une recette enregistrée, une proposition, ou un intitulé libre.');
        }

        $entry = match (true) {
            null !== $savedRecipeId => MealPlanEntry::forSavedRecipe(
                $household,
                $date,
                $slot,
                $savedRecipes->findOwned($household, $savedRecipeId)
                    ?? throw new NotFoundHttpException('Cette recette enregistrée n\'existe plus.'),
                $addedBy,
            ),
            [] !== $recipe => MealPlanEntry::forSnapshot($household, $date, $slot, $normalizer->normalize($recipe), $addedBy),
            null !== $label => MealPlanEntry::forFreeform($household, $date, $slot, $label, $addedBy),
            default => throw new StockException('Choisissez une recette enregistrée, une proposition, ou indiquez un intitulé libre.'),
        };

        $entityManager->persist($entry);
        $entityManager->flush();

        return $this->json($presenter->mealPlanEntry(new MealPlanEntryView($entry, null, [])), Response::HTTP_CREATED);
    }

    /** Le repas d'une entrée seule, avec son plan de préparation live — pour l'écran de préparation d'un snapshot. */
    #[Route('/entries/{id}', methods: ['GET'])]
    public function entry(
        string $id,
        RequestContext $context,
        MealPlanService $mealPlan,
        ApiPresenter $presenter,
    ): JsonResponse {
        $view = $this->findView($mealPlan, $context, $id);

        return $this->json($presenter->mealPlanEntry($view));
    }

    /** Replanification seulement — changer le contenu d'une entrée se fait par suppression puis création. */
    #[Route('/entries/{id}', methods: ['PATCH'])]
    public function rescheduleEntry(
        string $id,
        Request $request,
        RequestContext $context,
        MealPlanEntryRepository $mealPlanEntries,
        MealPlanService $mealPlan,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $entry = $this->ownedEntry($mealPlanEntries, $context, $id);
        $payload = JsonPayload::fromRequest($request);

        $entry->reschedule(
            $payload->date('date') ?? $entry->getDate(),
            $payload->enum('mealSlot', MealSlot::class) ?? $entry->getSlot(),
        );

        $entityManager->flush();

        return $this->json($presenter->mealPlanEntry($this->findView($mealPlan, $context, $id)));
    }

    #[Route('/entries/{id}', methods: ['DELETE'])]
    public function deleteEntry(
        string $id,
        RequestContext $context,
        MealPlanEntryRepository $mealPlanEntries,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $entityManager->remove($this->ownedEntry($mealPlanEntries, $context, $id));
        $entityManager->flush();

        return $this->json(['removed' => true]);
    }

    /**
     * Ajoute le manque cumulé du plan entier à la liste de courses, en un
     * seul geste — recalculé ici même, jamais rejoué depuis une réponse
     * client potentiellement périmée.
     */
    #[Route('/shopping-list', methods: ['POST'])]
    public function addShortfallToShoppingList(
        RequestContext $context,
        MealPlanService $mealPlan,
        ShoppingListService $shoppingList,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $overview = $mealPlan->overview($household);

        $entries = array_map(
            static fn (MealPlanShortfallLine $line): array => [
                'label' => $line->label,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
            ],
            $overview->shortfallSummary,
        );

        $created = $shoppingList->addMany($household, $context->member(), $entries);

        return $this->json([
            'items' => array_map(
                static fn ($item) => $presenter->shoppingListItem($item),
                $created,
            ),
        ], Response::HTTP_CREATED);
    }

    private function findView(MealPlanService $mealPlan, RequestContext $context, string $id): MealPlanEntryView
    {
        $overview = $mealPlan->overview($context->household());

        foreach ($overview->entries as $view) {
            if ((string) $view->entry->getId() === $id) {
                return $view;
            }
        }

        throw new NotFoundHttpException('Ce repas planifié n\'existe plus.');
    }

    private function ownedEntry(
        MealPlanEntryRepository $mealPlanEntries,
        RequestContext $context,
        string $id,
    ): MealPlanEntry {
        return $mealPlanEntries->findOwned($context->household(), $id)
            ?? throw new NotFoundHttpException('Ce repas planifié n\'existe plus.');
    }

    /** @return array<string, mixed> */
    private function presentOverview(MealPlanOverview $overview, ApiPresenter $presenter): array
    {
        return [
            'entries' => array_map(
                static fn (MealPlanEntryView $view): array => $presenter->mealPlanEntry($view),
                $overview->entries,
            ),
            'shortfallSummary' => array_map(
                static fn (MealPlanShortfallLine $line): array => $presenter->mealPlanShortfallLine($line),
                $overview->shortfallSummary,
            ),
            'unclaimedUrgentItems' => array_map(
                static fn ($candidate): array => [
                    'stockItem' => $presenter->stockItem($candidate->item),
                    'reasons' => array_map(
                        static fn ($reason): array => ['code' => $reason->value, 'label' => $reason->label()],
                        $candidate->reasons,
                    ),
                ],
                $overview->unclaimedUrgentItems,
            ),
        ];
    }
}
