<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\ProductLookupService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/products')]
class ProductController extends AbstractApiController
{
    /**
     * Résolution d'un code-barres scanné.
     *
     * Un 404 n'est pas une erreur applicative : la base Open Food Facts est
     * collaborative, l'application bascule alors sur la saisie manuelle.
     */
    #[Route('/barcode/{barcode}', methods: ['GET'], requirements: ['barcode' => '\d{6,20}'])]
    public function lookup(string $barcode, ProductLookupService $lookup, ApiPresenter $presenter): JsonResponse
    {
        $product = $lookup->findByBarcode($barcode);

        if (null === $product) {
            return $this->json([
                'found' => false,
                'barcode' => $barcode,
                'message' => 'Produit inconnu d\'Open Food Facts. Vous pouvez le saisir manuellement.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['found' => true, 'product' => $presenter->product($product)]);
    }

    /**
     * Combien de temps cet aliment se garde-t-il ?
     *
     * Prévisualisation de l'estimation avant l'ajout : l'utilisateur voit la date
     * proposée et sa justification, et peut la corriger. Une estimation qu'on ne
     * peut pas discuter finirait par n'être crue par personne.
     */
    #[Route('/shelf-life', methods: ['GET'])]
    public function shelfLife(
        Request $request,
        ShelfLifeEstimator $estimator,
        ApiPresenter $presenter,
    ): JsonResponse {
        $name = trim($request->query->getString('name'));

        if ('' === $name) {
            throw new BadRequestHttpException('Indiquez le nom de l\'aliment à estimer.');
        }

        $location = StorageLocation::tryFrom($request->query->getString('storageLocation'))
            ?? StorageLocation::Pantry;

        $traits = ProductTrait::fromValues(array_values(array_filter(
            explode(',', $request->query->getString('traits')),
            static fn (string $value) => '' !== $value,
        )));

        return $this->json($presenter->shelfLife($estimator->estimate(
            $name,
            $request->query->getString('category') ?: null,
            $traits,
            $location,
        )));
    }

    /**
     * Contribue une fiche au catalogue canonique.
     *
     * Le code-barres est obligatoire, et c'est le point : cette table est partagée
     * par tous les foyers. Sans identifiant universel, il n'y a rien de canonique
     * à enregistrer — seulement le nom que quelqu'un donne à son aliment, qui
     * appartient à sa variante de foyer et à personne d'autre.
     */
    #[Route('', methods: ['POST'])]
    public function createManual(
        Request $request,
        ProductLookupService $lookup,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $barcode = $payload->string('barcode');

        if (null === $barcode || 1 !== preg_match('/^\d{6,20}$/', $barcode)) {
            throw new BadRequestHttpException(
                'Le catalogue partagé n\'accepte que des fiches identifiées par un code-barres. '
                .'Pour un aliment sans code-barres, ajoutez-le directement à votre stock.',
            );
        }

        $product = $lookup->createManual(
            name: $payload->requiredString('name'),
            brand: $payload->string('brand'),
            category: $payload->string('category'),
            packageQuantity: $payload->float('packageQuantity'),
            packageUnit: $payload->enum('packageUnit', Unit::class),
            barcode: $barcode,
        );

        return $this->json($presenter->product($product), Response::HTTP_CREATED);
    }
}
