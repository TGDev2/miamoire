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
use App\Repository\ShoppingListItemRepository;
use App\Repository\StockItemRepository;
use App\Service\Food\FoodNatureDetector;
use App\Service\Food\FreshFoodLexicon;
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
        ShoppingListItemRepository $shoppingList,
        StockItemRepository $stockItems,
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

        // Ce produit était-il attendu ? Rapproche toute ligne active, cochée
        // ou non — plus indulgent que d'exiger d'avoir pensé à cocher en
        // magasin.
        $shoppingListItem = null !== $variant ? $shoppingList->findOneActiveByVariant($household, $variant) : null;

        return $this->json([
            'found' => true,
            'product' => $presenter->product($product),
            'householdVariant' => null !== $variant ? $presenter->variant($variant) : null,
            'shoppingListItem' => null !== $shoppingListItem ? $presenter->shoppingListItem($shoppingListItem) : null,
            // Le mode « vérifier avant d'acheter » (Évolution 3) : ce qu'il y a
            // déjà chez soi pour ce produit, sans devoir ouvrir la fiche détaillée.
            'currentStock' => null !== $variant
                ? $presenter->currentStockSummary($stockItems->findAvailableByVariant($variant))
                : null,
        ]);
    }

    /**
     * De quel genre d'aliment s'agit-il, et combien de temps se garde-t-il ?
     *
     * Cette route ne répondait qu'à la seconde question. La première la précède
     * pourtant : c'est elle qui décide du formulaire à afficher. Demander à
     * quelqu'un « quel poids par exemplaire ? » pour une courgette est une question
     * qui n'existe pas — il faut le savoir AVANT de dessiner l'écran, pas après.
     *
     * L'estimation reste renvoyée avec sa justification : une date déduite qu'on ne
     * peut pas discuter finirait par n'être crue par personne.
     */
    #[Route('/profile', methods: ['GET'])]
    public function profile(
        Request $request,
        RequestContext $context,
        FoodNatureDetector $natureDetector,
        FreshFoodLexicon $lexicon,
        ShelfLifeEstimator $estimator,
        ApiPresenter $presenter,
    ): JsonResponse {
        $context->household();

        $name = trim($request->query->getString('name'));

        if ('' === $name) {
            throw new BadRequestHttpException('Indiquez le nom de l\'aliment à décrire.');
        }

        $location = StorageLocation::tryFrom($request->query->getString('storageLocation'))
            ?? StorageLocation::Pantry;

        $category = $request->query->getString('category') ?: null;

        $traits = ProductTrait::fromValues(array_values(array_filter(
            explode(',', $request->query->getString('traits')),
            static fn (string $value) => '' !== $value,
        )));

        return $this->json($presenter->foodProfile(
            // `scanned` dit que le libellé vient d'une fiche à code-barres, donc
            // d'un emballage : c'est ce qui distingue une courgette du marché
            // d'une barquette de courgettes déjà datée.
            $natureDetector->detect($name, $category, $traits, $request->query->getBoolean('scanned')),
            $lexicon->find($name),
            $estimator->estimate($name, $category, $traits, $location),
        ));
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
