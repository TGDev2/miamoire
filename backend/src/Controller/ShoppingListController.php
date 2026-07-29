<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ShoppingListItem;
use App\Enum\Unit;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\ShoppingListItemRepository;
use App\Service\Shopping\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La liste de courses du foyer — pas de clé d'opération : ce sont des
 * écritures peu coûteuses, réversibles en un geste, et déjà idempotentes par
 * construction (voir {@see ShoppingListService::addMany()}).
 */
#[Route('/api/shopping-list')]
class ShoppingListController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function list(
        RequestContext $context,
        ShoppingListItemRepository $items,
        ApiPresenter $presenter,
    ): JsonResponse {
        return $this->json([
            'items' => array_map(
                static fn (ShoppingListItem $item) => $presenter->shoppingListItem($item),
                $items->findForHousehold($context->household()),
            ),
        ]);
    }

    /**
     * Ajout groupé — sert autant la saisie manuelle (un seul élément) que
     * l'ajout de tous les ingrédients manquants d'une recette en un geste.
     */
    #[Route('/items', methods: ['POST'])]
    public function addItems(
        Request $request,
        RequestContext $context,
        ShoppingListService $service,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $entries = array_map(
            static function (array $raw): array {
                $line = JsonPayload::fromArray($raw);

                return [
                    'label' => $line->requiredString('label'),
                    'quantity' => $line->float('quantity'),
                    'unit' => $line->requiredEnum('unit', Unit::class),
                ];
            },
            $payload->objectList('items'),
        );

        $created = $service->addMany($context->household(), $context->member(), $entries);

        return $this->json([
            'items' => array_map(
                static fn (ShoppingListItem $item) => $presenter->shoppingListItem($item),
                $created,
            ),
        ], Response::HTTP_CREATED);
    }

    /** Coche ou décoche une ligne. */
    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        string $id,
        Request $request,
        RequestContext $context,
        ShoppingListItemRepository $items,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedShoppingListItem($items, $context, $id);
        $payload = JsonPayload::fromRequest($request);

        if ($payload->has('checked')) {
            $item->setChecked($payload->bool('checked'));
        }

        $entityManager->flush();

        return $this->json($presenter->shoppingListItem($item));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        string $id,
        RequestContext $context,
        ShoppingListItemRepository $items,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $item = $this->ownedShoppingListItem($items, $context, $id);

        $entityManager->remove($item);
        $entityManager->flush();

        return $this->json(['removed' => true]);
    }

    private function ownedShoppingListItem(
        ShoppingListItemRepository $items,
        RequestContext $context,
        string $id,
    ): ShoppingListItem {
        $item = $items->findOwned($context->household(), $id);

        if (null === $item) {
            throw new NotFoundHttpException('Élément de la liste de courses introuvable.');
        }

        return $item;
    }
}
