<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Enum\FillLevel;
use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\ProductRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Service\ProductLookupService;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/stock-items')]
class StockItemController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function list(
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        ApiPresenter $presenter,
    ): JsonResponse {
        $location = $request->query->getString('location');
        $expiring = $request->query->get('expiringWithinDays');

        $items = $stockItems->search($context->household(), [
            'location' => '' !== $location ? StorageLocation::tryFrom($location) : null,
            'category' => $request->query->getString('category') ?: null,
            'search' => $request->query->getString('search') ?: null,
            'expiringWithinDays' => is_numeric($expiring) ? (int) $expiring : null,
            'includeEmpty' => $request->query->getBoolean('includeEmpty'),
        ]);

        return $this->json([
            'items' => array_map(
                static fn ($item) => $presenter->stockItem($item),
                $items,
            ),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(
        Request $request,
        RequestContext $context,
        StockService $stockService,
        ProductLookupService $lookup,
        ProductRepository $products,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $product = $this->resolveProduct($payload, $lookup, $products);

        // Un état saisi explicitement prime sur celui déduit du libellé, quelle que
        // soit l'origine du produit — code-barres, fiche existante ou saisie libre.
        if ($payload->has('traits')) {
            $product->setTraits(ProductTrait::fromValues($payload->stringList('traits')));
        }

        $unit = $payload->enum('unit', Unit::class) ?? $product->getPackageUnit() ?? Unit::Piece;

        $item = $stockService->add(
            household: $context->household(),
            author: $context->requireMember(),
            product: $product,
            numberOfPackages: $payload->int('numberOfPackages', 1) ?? 1,
            quantityPerPackage: $payload->float('quantityPerPackage')
                ?? $product->getPackageQuantity()
                ?? 1.0,
            unit: $unit,
            location: $payload->enum('storageLocation', StorageLocation::class) ?? StorageLocation::Pantry,
            purchaseDate: $payload->date('purchaseDate'),
            expirationDate: $payload->date('expirationDate'),
            note: $payload->string('note'),
            quantityIsEstimated: $payload->bool('quantityIsEstimated'),
        );

        return $this->json($presenter->stockItem($item), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(
        string $id,
        RequestContext $context,
        StockItemRepository $stockItems,
        ApiPresenter $presenter,
    ): JsonResponse {
        return $this->json($presenter->stockItem($this->ownedStockItem($stockItems, $context, $id)));
    }

    /** Modifications sans impact quantitatif : emplacement, dates, nom, état ouvert. */
    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $payload = JsonPayload::fromRequest($request);

        if ($payload->has('storageLocation')) {
            $item->setStorageLocation($payload->requiredEnum('storageLocation', StorageLocation::class));
        }

        if ($payload->has('expirationDate')) {
            $item->setExpirationDate($payload->date('expirationDate'));
        }

        if ($payload->has('purchaseDate')) {
            $item->setPurchaseDate($payload->date('purchaseDate'));
        }

        if ($payload->has('note')) {
            $item->setNote($payload->string('note'));
        }

        if ($payload->has('name')) {
            $item->getProduct()->setName($payload->requiredString('name'));
        }

        // L'état du produit (en conserve, épluché…) conditionne les étapes de recette.
        if ($payload->has('traits')) {
            $item->getProduct()->setTraits(ProductTrait::fromValues($payload->stringList('traits')));
        }

        if ($payload->has('isOpened')) {
            $stockService->markOpened($item, $payload->bool('isOpened'));
        }

        // Toute variation de quantité passe par une correction journalisée.
        if ($payload->has('remainingQuantity')) {
            $stockService->correct(
                $item,
                $payload->requiredFloat('remainingQuantity'),
                $payload->enum('unit', Unit::class) ?? $item->getUnit(),
                $context->requireMember(),
                $payload->string('reason'),
            );
        }

        $entityManager->flush();

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        string $id,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
    ): JsonResponse {
        $stockService->delete($this->ownedStockItem($stockItems, $context, $id));

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/consume', methods: ['POST'])]
    public function consume(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $payload = JsonPayload::fromRequest($request);
        $mode = $payload->string('mode', 'quantity');
        $member = $context->requireMember();

        match ($mode) {
            'all' => $stockService->consumeAll($item, $member, $payload->string('reason')),
            'package' => $stockService->consumeOnePackage($item, $member),
            'units' => $stockService->adjustDiscreteUnits($item, $payload->int('delta', -1) ?? -1, $member),
            'quantity' => $stockService->consume(
                $item,
                $payload->requiredFloat('quantity'),
                $payload->enum('unit', Unit::class) ?? $item->getUnit(),
                $member,
                $payload->string('reason'),
            ),
            default => throw new BadRequestHttpException(sprintf('Mode de consommation « %s » inconnu.', $mode)),
        };

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}/add', methods: ['POST'])]
    public function addQuantity(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $payload = JsonPayload::fromRequest($request);

        $stockService->addQuantity(
            $item,
            $payload->requiredFloat('quantity'),
            $payload->enum('unit', Unit::class) ?? $item->getUnit(),
            $context->requireMember(),
            $payload->string('reason'),
        );

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}/correct', methods: ['POST'])]
    public function correct(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();

        // Correction approximative (« environ la moitié ») ou quantité exacte.
        $fillLevel = $payload->enum('fillLevel', FillLevel::class);

        if (null !== $fillLevel) {
            $stockService->applyFillLevel($item, $fillLevel, $member);
        } else {
            $stockService->correct(
                $item,
                $payload->requiredFloat('quantity'),
                $payload->enum('unit', Unit::class) ?? $item->getUnit(),
                $member,
                $payload->string('reason'),
            );
        }

        return $this->json($presenter->stockItem($item));
    }

    /**
     * Répare une unité mal choisie à la saisie — « g » au lieu de « unité ».
     *
     * `quantity` n'est requis que si la nouvelle unité appartient à une autre
     * famille : convertir des grammes en unités demanderait un poids unitaire
     * qu'on n'a pas. C'est refusé plutôt que deviné.
     */
    #[Route('/{id}/unit', methods: ['POST'])]
    public function changeUnit(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $payload = JsonPayload::fromRequest($request);

        $stockService->changeUnit(
            $item,
            $payload->requiredEnum('unit', Unit::class),
            $payload->float('quantity'),
            $context->requireMember(),
        );

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}/open', methods: ['POST'])]
    public function open(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $stockService->markOpened($item, JsonPayload::fromRequest($request)->bool('isOpened', true));

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}/discard', methods: ['POST'])]
    public function discard(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);
        $stockService->discard(
            $item,
            $context->requireMember(),
            JsonPayload::fromRequest($request)->string('reason'),
        );

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}/movements', methods: ['GET'])]
    public function movements(
        string $id,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockMovementRepository $movements,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);

        return $this->json([
            'movements' => array_map(
                static fn ($movement) => $presenter->movement($movement),
                $movements->findForStockItem($item),
            ),
        ]);
    }

    /**
     * Trois façons d'identifier le produit ajouté, par ordre de précision :
     * un produit déjà connu, un code-barres à résoudre, ou une saisie libre
     * (fruits et légumes, vrac, restes, préparations maison).
     */
    private function resolveProduct(
        JsonPayload $payload,
        ProductLookupService $lookup,
        ProductRepository $products,
    ): Product {
        $productId = $payload->string('productId');
        if (null !== $productId && Uuid::isValid($productId)) {
            $product = $products->find(Uuid::fromString($productId));
            if (null !== $product) {
                return $product;
            }
        }

        $barcode = $payload->string('barcode');
        if (null !== $barcode) {
            $product = $lookup->findByBarcode($barcode);
            if (null !== $product) {
                return $product;
            }
        }

        $name = $payload->string('name');
        if (null === $name) {
            throw new BadRequestHttpException(
                'Indiquez un produit existant, un code-barres reconnu, ou au minimum un nom.',
            );
        }

        return $lookup->createManual(
            name: $name,
            brand: $payload->string('brand'),
            category: $payload->string('category'),
            packageQuantity: $payload->float('quantityPerPackage'),
            packageUnit: $payload->enum('unit', Unit::class),
            barcode: $barcode,
        );
    }
}
