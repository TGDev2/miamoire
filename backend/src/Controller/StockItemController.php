<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Entity\StockItem;
use App\Entity\StockOperation;
use App\Enum\FillLevel;
use App\Enum\ProductTrait;
use App\Enum\StockOperationType;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Exception\StockConflictException;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\ProductRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\Product\VariantResolver;
use App\Service\ProductLookupService;
use App\Service\Stock\StockOperationGuard;
use App\Service\Stock\StockService;
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
     * `batches` décrit donc les dates, et tout le reste est commun. `operationKey`
     * rend le geste rejouable sans effet supplémentaire : un double appui, ou une
     * réponse réseau perdue suivie d'une nouvelle tentative, ne crée pas deux fois
     * les mêmes lots.
     */
    #[Route('', methods: ['POST'])]
    public function create(
        Request $request,
        RequestContext $context,
        StockService $stockService,
        StockOperationGuard $guard,
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

        $response = $guard->run(
            $household,
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $author,
            $stockService,
            // Un ajout partiel — trois lots créés, le quatrième en échec — laisserait
            // un inventaire que personne n'a saisi. Tout ou rien : une erreur de saisie
            // sur un batch n'est pas rattrapée ici (voir StockOperationGuard), elle
            // remonte et fait annuler toute la transaction, batches déjà créés compris.
            function () use ($stockService, $shelfLife, $household, $author, $variant, $unit, $defaults, $batches, $presenter): array {
                $items = array_map(
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
                );

                return ['items' => array_map(static fn (StockItem $item) => $presenter->stockItem($item), $items)];
            },
        );

        return $this->json($response, Response::HTTP_CREATED);
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

    /**
     * Modifications sans impact quantitatif : emplacement, dates, nom, état ouvert.
     * Peut aussi porter `remainingQuantity`, auquel cas `expectedRevision` — si
     * fourni — protège contre l'écrasement d'un changement qu'on n'a pas vu.
     */
    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        VariantResolver $variants,
        ShelfLifeEstimator $shelfLife,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $expectedRevision = $payload->int('expectedRevision');

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $context->member(),
            $stockService,
            function (StockOperation $operation) use (
                $id,
                $context,
                $stockItems,
                $stockService,
                $variants,
                $shelfLife,
                $presenter,
                $payload,
                $expectedRevision,
            ): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);

                if (null !== $expectedRevision && $expectedRevision !== $item->getRevision()) {
                    throw new StockConflictException(
                        'Ce lot a été modifié entre-temps : revoyez son état avant de recommencer.',
                        ['current' => $presenter->stockItem($item, $stockItems->findSiblings($item))],
                    );
                }

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
                        $operation,
                    );
                }

                return $presenter->stockItem($item, $stockItems->findSiblings($item));
            },
        );

        return $this->json($response);
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
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use ($id, $context, $stockItems, $stockService, $presenter, $payload, $member): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);
                $stockService->archive($item, $member, $payload->string('reason'), $operation);

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    #[Route('/{id}/restore', methods: ['POST'])]
    public function restore(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use ($id, $context, $stockItems, $stockService, $presenter, $member): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);
                $stockService->restore($item, $member, $operation);

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    #[Route('/{id}/consume', methods: ['POST'])]
    public function consume(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $mode = $payload->string('mode', 'quantity');
        $member = $context->requireMember();

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use ($id, $context, $stockItems, $stockService, $presenter, $payload, $member, $mode): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);

                match ($mode) {
                    'all' => $stockService->consumeAll($item, $member, $payload->string('reason'), $operation),
                    'package' => $stockService->consumeOnePackage($item, $member, $operation),
                    'units' => $stockService->adjustDiscreteUnits($item, $payload->int('delta', -1) ?? -1, $member, $operation),
                    'quantity' => $stockService->consume(
                        $item,
                        $payload->requiredFloat('quantity'),
                        $payload->enum('unit', Unit::class) ?? $item->getUnit(),
                        $member,
                        $payload->string('reason'),
                        $operation,
                    ),
                    default => throw new BadRequestHttpException(sprintf('Mode de consommation « %s » inconnu.', $mode)),
                };

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    #[Route('/{id}/add', methods: ['POST'])]
    public function addQuantity(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use ($id, $context, $stockItems, $stockService, $presenter, $payload, $member): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);

                $stockService->addQuantity(
                    $item,
                    $payload->requiredFloat('quantity'),
                    $payload->enum('unit', Unit::class) ?? $item->getUnit(),
                    $member,
                    $payload->string('reason'),
                    $operation,
                );

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    /** Correction absolue : `expectedRevision`, si fourni, protège contre une écriture périmée. */
    #[Route('/{id}/correct', methods: ['POST'])]
    public function correct(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();
        $expectedRevision = $payload->int('expectedRevision');

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use (
                $id,
                $context,
                $stockItems,
                $stockService,
                $presenter,
                $payload,
                $member,
                $expectedRevision,
            ): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);

                if (null !== $expectedRevision && $expectedRevision !== $item->getRevision()) {
                    throw new StockConflictException(
                        'Ce lot a été modifié entre-temps : revoyez son état avant de recommencer.',
                        ['current' => $presenter->stockItem($item)],
                    );
                }

                // Correction approximative (« environ la moitié ») ou quantité exacte.
                $fillLevel = $payload->enum('fillLevel', FillLevel::class);

                if (null !== $fillLevel) {
                    $stockService->applyFillLevel($item, $fillLevel, $member, $operation);
                } else {
                    $stockService->correct(
                        $item,
                        $payload->requiredFloat('quantity'),
                        $payload->enum('unit', Unit::class) ?? $item->getUnit(),
                        $member,
                        $payload->string('reason'),
                        $operation,
                    );
                }

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    /**
     * Répare une unité mal choisie à la saisie — « g » au lieu de « unité ».
     *
     * `quantity` n'est requis que si la nouvelle unité appartient à une autre
     * famille : convertir des grammes en unités demanderait un poids unitaire
     * qu'on n'a pas. C'est refusé plutôt que deviné. Le lot rejoint alors la
     * variante correspondante du foyer. `expectedRevision`, si fourni, protège
     * contre une écriture fondée sur une lecture périmée.
     */
    #[Route('/{id}/unit', methods: ['POST'])]
    public function changeUnit(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();
        $expectedRevision = $payload->int('expectedRevision');

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use (
                $id,
                $context,
                $stockItems,
                $stockService,
                $presenter,
                $payload,
                $member,
                $expectedRevision,
            ): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);

                if (null !== $expectedRevision && $expectedRevision !== $item->getRevision()) {
                    throw new StockConflictException(
                        'Ce lot a été modifié entre-temps : revoyez son état avant de recommencer.',
                        ['current' => $presenter->stockItem($item)],
                    );
                }

                $stockService->changeUnit(
                    $item,
                    $payload->requiredEnum('unit', Unit::class),
                    $payload->float('quantity'),
                    $member,
                    $operation,
                );

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    #[Route('/{id}/open', methods: ['POST'])]
    public function open(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $context->member(),
            $stockService,
            function () use ($id, $context, $stockItems, $stockService, $presenter, $payload): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);
                $stockService->markOpened($item, $payload->bool('isOpened', true));

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
    }

    #[Route('/{id}/discard', methods: ['POST'])]
    public function discard(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        StockService $stockService,
        StockOperationGuard $guard,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $member = $context->requireMember();

        $response = $guard->run(
            $context->household(),
            $payload->requiredString('operationKey'),
            StockOperationType::StockItemMutation,
            $this->requestHash($payload),
            $member,
            $stockService,
            function (StockOperation $operation) use ($id, $context, $stockItems, $stockService, $presenter, $payload, $member): array {
                $item = $this->ownedStockItemForUpdate($stockItems, $context, $id);
                $stockService->discard($item, $member, $payload->string('reason'), $operation);

                return $presenter->stockItem($item);
            },
        );

        return $this->json($response);
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

    /**
     * L'empreinte d'une requête, pour reconnaître qu'une clé d'opération rejouée
     * porte bien le même geste — et refuser plutôt que de la confondre avec un
     * autre si son contenu diffère. `operationKey`/`expectedRevision` sont des
     * détails de transport, pas le geste lui-même : ils n'entrent pas dans l'empreinte.
     */
    private function requestHash(JsonPayload $payload): string
    {
        $data = $payload->raw();
        unset($data['operationKey'], $data['expectedRevision']);

        return hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
