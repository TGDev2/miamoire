<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\HouseholdProductVariant;
use App\Http\ApiPresenter;
use App\Http\RequestContext;
use App\Repository\HouseholdProductVariantRepository;
use App\Repository\ShoppingListItemRepository;
use App\Service\Shopping\ShoppingAdvisorCandidate;
use App\Service\Shopping\ShoppingAdvisorSelector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Qu'est-ce que je ne dois surtout pas racheter ? » — `tasks/evolutions.md`
 * (Évolution 3). Aucune route « accepter » : le mobile agit directement sur la
 * liste de courses existante (`/api/shopping-list`), déjà idempotente et peu
 * coûteuse (voir sa doc) — cette API se contente de proposer et de laisser
 * désactiver, comme demandé par le document.
 */
#[Route('/api/shopping-advisor')]
class ShoppingAdvisorController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function list(
        Request $request,
        RequestContext $context,
        ShoppingAdvisorSelector $selector,
        ShoppingListItemRepository $shoppingList,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();
        $limit = $request->query->getInt('limit', 6);

        $candidates = $selector->select($household, $limit);

        return $this->json([
            'items' => array_map(
                fn (ShoppingAdvisorCandidate $candidate): array => $presenter->shoppingAdvice(
                    $candidate,
                    $shoppingList->findOneActiveByVariant($household, $candidate->variant),
                ),
                $candidates,
            ),
        ]);
    }

    /** Les variantes désactivées par ce foyer, pour l'écran qui permet de les réactiver. */
    #[Route('/muted', methods: ['GET'])]
    public function muted(
        RequestContext $context,
        HouseholdProductVariantRepository $variants,
        ApiPresenter $presenter,
    ): JsonResponse {
        return $this->json([
            'items' => array_map(
                fn (HouseholdProductVariant $variant): array => $presenter->variant($variant),
                $variants->findMuted($context->household()),
            ),
        ]);
    }

    #[Route('/{id}/mute', methods: ['POST'])]
    public function mute(
        string $id,
        RequestContext $context,
        HouseholdProductVariantRepository $variants,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $variant = $this->ownedVariant($variants, $context, $id);
        $variant->muteShoppingAdvice(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json($presenter->variant($variant));
    }

    #[Route('/{id}/unmute', methods: ['POST'])]
    public function unmute(
        string $id,
        RequestContext $context,
        HouseholdProductVariantRepository $variants,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $variant = $this->ownedVariant($variants, $context, $id);
        $variant->unmuteShoppingAdvice();
        $entityManager->flush();

        return $this->json($presenter->variant($variant));
    }
}
