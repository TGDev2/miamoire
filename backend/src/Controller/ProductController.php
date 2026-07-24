<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Unit;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Service\ProductLookupService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    #[Route('', methods: ['POST'])]
    public function createManual(
        Request $request,
        ProductLookupService $lookup,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $product = $lookup->createManual(
            name: $payload->requiredString('name'),
            brand: $payload->string('brand'),
            category: $payload->string('category'),
            packageQuantity: $payload->float('packageQuantity'),
            packageUnit: $payload->enum('packageUnit', Unit::class),
            barcode: $payload->string('barcode'),
        );

        return $this->json($presenter->product($product), Response::HTTP_CREATED);
    }
}
