<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Freshness\ShelfLifeEstimator;
use App\Service\ProductLookupService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le catalogue partagé — et pourquoi ces routes exigent un foyer.
 *
 * Elles ne renvoient aucune donnée d'inventaire, ce qui les a longtemps fait
 * passer pour inoffensives. Elles ne le sont pas : `POST /api/products` écrit
 * dans une table partagée par tous les foyers, et `GET /barcode/{barcode}`
 * déclenche un appel sortant vers Open Food Facts, dont le quota est commun.
 * Sans code d'accès, n'importe qui pouvait donc polluer le catalogue de tout le
 * monde ou épuiser le quota de l'API. La règle documentée — santé, création,
 * adhésion, et rien d'autre — s'applique désormais ici aussi.
 */
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
    public function lookup(
        string $barcode,
        RequestContext $context,
        ProductLookupService $lookup,
        HouseholdProductVariantRepository $variants,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $context->household();

        $product = $lookup->findByBarcode($barcode);

        if (null === $product) {
            return $this->json([
                'found' => false,
                'barcode' => $barcode,
                'message' => 'Produit inconnu d\'Open Food Facts. Vous pouvez le saisir manuellement.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Ce foyer a-t-il déjà acheté ce produit ? Sert à préremplir le
        // « Retour de courses » depuis les habitudes d'achat plutôt que
        // depuis les seuls défauts du catalogue partagé.
        $variant = $variants->findOneByProduct($household, $product);

        return $this->json([
            'found' => true,
            'product' => $presenter->product($product),
            'householdVariant' => null !== $variant ? $presenter->variant($variant) : null,
        ]);
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
        RequestContext $context,
        ShelfLifeEstimator $estimator,
        ApiPresenter $presenter,
    ): JsonResponse {
        $context->household();

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
        RequestContext $context,
        ProductLookupService $lookup,
        ApiPresenter $presenter,
    ): JsonResponse {
        // Le foyer d'abord : sans quoi un appel sans code mais sans en-tête de
        // membre repartirait en 400 « indiquez qui agit » au lieu du 401 qu'il
        // mérite. Puis le membre, car c'est une écriture — la règle documentée
        // vaut ici comme ailleurs, même si la fiche produite n'appartient à aucun
        // foyer en particulier.
        $context->household();
        $context->requireMember();

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
