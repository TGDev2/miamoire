<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Entity\StockItem;
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
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\Product\VariantResolver;
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
            // Les lots archivés ne reviennent que si on les demande explicitement.
            'archived' => $request->query->getBoolean('archived'),
        ]);

        return $this->json([
            'items' => array_map(
                static fn ($item) => $presenter->stockItem($item),
                $items,
            ),
        ]);
    }

    /**
     * Ajoute un ou plusieurs lots du même aliment.
     *
     * Trois yaourts périmant le 27, deux le 3 août : c'est un seul geste de courses
     * et cinq exemplaires du même produit, mais deux lots. Les saisir un par un
     * obligeait à refaire tout le formulaire ; les fondre en un seul lot aurait
     * fait mentir la date la plus proche, donc les alertes et FEFO.
     *
     * `batches` décrit donc les dates, et tout le reste est commun.
     */
    #[Route('', methods: ['POST'])]
    public function create(
        Request $request,
        RequestContext $context,
        StockService $stockService,
        ProductLookupService $lookup,
        ProductRepository $products,
        VariantResolver $variants,
        ShelfLifeEstimator $shelfLife,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $household = $context->household();
        $author = $context->requireMember();

        $product = $this->resolveCatalogProduct($payload, $lookup, $products);
        $name = $payload->string('name') ?? $product?->getName();

        if (null === $name) {
            throw new BadRequestHttpException(
                'Indiquez un produit existant, un code-barres reconnu, ou au minimum un nom.',
            );
        }

        $unit = $payload->enum('unit', Unit::class) ?? $product?->getPackageUnit() ?? Unit::Piece;

        // La correction locale l'emporte sur l'état déduit ; ni l'une ni l'autre
        // ne touche plus le catalogue partagé entre foyers.
        $variant = $variants->resolve(
            household: $household,
            product: $product,
            name: $name,
            unit: $unit,
            explicitTraits: $payload->has('traits')
                ? ProductTrait::fromValues($payload->stringList('traits'))
                : null,
            brand: $payload->string('brand'),
            category: $payload->string('category'),
        );

        $defaults = [
            'numberOfPackages' => $payload->int('numberOfPackages', 1) ?? 1,
            'quantityPerPackage' => $payload->float('quantityPerPackage') ?? $product?->getPackageQuantity() ?? 1.0,
            'storageLocation' => $payload->enum('storageLocation', StorageLocation::class) ?? StorageLocation::Pantry,
            'note' => $payload->string('note'),
            'quantityIsEstimated' => $payload->bool('quantityIsEstimated'),
            'expirationDate' => $payload->date('expirationDate'),
            'estimateExpiration' => $payload->bool('estimateExpiration'),
        ];

        $batches = $payload->objectList('batches');
        if ([] === $batches) {
            $batches = [[]];
        }

        // Un ajout partiel — trois lots créés, le quatrième en échec — laisserait
        // un inventaire que personne n'a saisi. Tout ou rien.
        $items = $stockService->transactional(
            fn (): array => array_map(
                fn (array $batch): StockItem => $this->addBatch(
                    $stockService,
                    $shelfLife,
                    $household,
                    $author,
                    $variant,
                    $unit,
                    $defaults,
                    $batch,
                ),
                $batches,
            ),
        );

        return $this->json(
            ['items' => array_map(static fn (StockItem $item) => $presenter->stockItem($item), $items)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(
        string $id,
        RequestContext $context,
        StockItemRepository $stockItems,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $this->ownedStockItem($stockItems, $context, $id);

        return $this->json($presenter->stockItem($item, $stockItems->findSiblings($item)));
    }

    /** Modifications sans impact quantitatif : emplacement, dates, nom, état ouvert. */
    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        VariantResolver $variants,
        ShelfLifeEstimator $shelfLife,
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

        // « Je n'ai pas de date, mais estime-la » : recalculée depuis l'aliment,
        // son état et l'endroit où il est rangé.
        if ($payload->bool('estimateExpiration')) {
            $item->setEstimatedExpirationDate($this->estimateFor($shelfLife, $item->getVariant(), $item->getStorageLocation()));
        }

        if ($payload->has('purchaseDate')) {
            $item->setPurchaseDate($payload->date('purchaseDate'));
        }

        if ($payload->has('note')) {
            $item->setNote($payload->string('note'));
        }

        // Renommer, c'est renommer DANS CE FOYER : le catalogue partagé n'est plus
        // touché, et la clé culinaire est régénérée du même geste.
        if ($payload->has('name')) {
            $item->setVariant($variants->rename($item->getVariant(), $payload->requiredString('name')));
        }

        // L'état du produit (en conserve, épluché…) conditionne les étapes de recette.
        if ($payload->has('traits')) {
            $item->setVariant($variants->changeTraits(
                $item->getVariant(),
                ProductTrait::fromValues($payload->stringList('traits')),
            ));
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

        return $this->json($presenter->stockItem($item, $stockItems->findSiblings($item)));
    }

    /**
     * Archive le lot. Ce n'est plus une suppression physique : le journal des
     * mouvements survit, et le geste se défait par `/restore`.
     */
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $stockService->archive(
            $this->ownedStockItem($stockItems, $context, $id),
            $context->requireMember(),
            JsonPayload::fromRequest($request)->string('reason'),
        );

        return $this->json($presenter->stockItem($item));
    }

    #[Route('/{id}/restore', methods: ['POST'])]
    public function restore(
        string $id,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        ApiPresenter $presenter,
    ): JsonResponse {
        $item = $stockService->restore(
            $this->ownedStockItem($stockItems, $context, $id),
            $context->requireMember(),
        );

        return $this->json($presenter->stockItem($item));
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
     * qu'on n'a pas. C'est refusé plutôt que deviné. Le lot rejoint alors la
     * variante correspondante du foyer.
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
     * @param array{numberOfPackages: int, quantityPerPackage: float, storageLocation: StorageLocation, note: ?string, quantityIsEstimated: bool, expirationDate: ?\DateTimeImmutable, estimateExpiration: bool} $defaults
     * @param array<string, mixed>                                                                                                                                                                              $batch
     */
    private function addBatch(
        StockService $stockService,
        ShelfLifeEstimator $shelfLife,
        \App\Entity\Household $household,
        \App\Entity\Member $author,
        HouseholdProductVariant $variant,
        Unit $unit,
        array $defaults,
        array $batch,
    ): StockItem {
        $line = JsonPayload::fromArray($batch);

        $location = $line->enum('storageLocation', StorageLocation::class) ?? $defaults['storageLocation'];
        $expiration = $line->has('expirationDate') ? $line->date('expirationDate') : $defaults['expirationDate'];
        $estimate = $line->has('estimateExpiration') ? $line->bool('estimateExpiration') : $defaults['estimateExpiration'];

        // Une date lue sur l'emballage prime toujours : on n'estime que ce qui manque.
        $estimated = false;
        if (null === $expiration && $estimate) {
            $expiration = $this->estimateFor($shelfLife, $variant, $location);
            $estimated = true;
        }

        return $stockService->add(
            household: $household,
            author: $author,
            variant: $variant,
            numberOfPackages: $line->int('numberOfPackages') ?? $defaults['numberOfPackages'],
            quantityPerPackage: $line->float('quantityPerPackage') ?? $defaults['quantityPerPackage'],
            unit: $unit,
            location: $location,
            purchaseDate: $line->date('purchaseDate'),
            expirationDate: $expiration,
            note: $line->string('note') ?? $defaults['note'],
            quantityIsEstimated: $line->has('quantityIsEstimated')
                ? $line->bool('quantityIsEstimated')
                : $defaults['quantityIsEstimated'],
            expirationIsEstimated: $estimated,
        );
    }

    private function estimateFor(
        ShelfLifeEstimator $shelfLife,
        HouseholdProductVariant $variant,
        StorageLocation $location,
    ): \DateTimeImmutable {
        return $shelfLife->estimate(
            $variant->getName(),
            $variant->getCategory(),
            $variant->getTraits(),
            $location,
        )->until();
    }

    /**
     * La fiche canonique, quand il y en a une.
     *
     * Une saisie libre — fruits et légumes, vrac, restes, préparations maison —
     * n'en crée plus : elle crée une variante du foyer et rien d'autre. Le
     * catalogue partagé mondialement ne se remplit que par code-barres.
     */
    private function resolveCatalogProduct(
        JsonPayload $payload,
        ProductLookupService $lookup,
        ProductRepository $products,
    ): ?Product {
        $productId = $payload->string('productId');
        if (null !== $productId && Uuid::isValid($productId)) {
            $product = $products->find(Uuid::fromString($productId));
            if (null !== $product) {
                return $product;
            }
        }

        $barcode = $payload->string('barcode');
        if (null !== $barcode) {
            return $lookup->findByBarcode($barcode);
        }

        return null;
    }
}
