<?php

declare(strict_types=1);

namespace App\Service\Shopping;

use App\Entity\Household;
use App\Entity\Member;
use App\Entity\ShoppingListItem;
use App\Enum\Unit;
use App\Repository\ShoppingListItemRepository;
use App\Service\Product\VariantResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ajoute des ingrédients à la liste de courses du foyer.
 *
 * Chaque entrée est résolue en variante — la même fonction pure que le stock
 * utilise — avant même qu'aucun achat n'existe : c'est cette résolution qui
 * permettra de reconnaître le produit au scan, au retour de courses (voir
 * {@see \App\Repository\HouseholdProductVariantRepository::findOneByProduct}
 * et {@see ShoppingListItemRepository::findOneActiveByVariant}).
 *
 * Ajouter deux fois le même ingrédient ne cumule pas les quantités : la ligne
 * déjà active est renvoyée telle quelle. Une liste de courses dit quoi
 * acheter, rarement une quantité au gramme près entre deux recettes.
 */
class ShoppingListService
{
    public function __construct(
        private readonly ShoppingListItemRepository $items,
        private readonly VariantResolver $variants,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{label: string, quantity: ?float, unit: Unit}> $entries
     *
     * @return list<ShoppingListItem>
     */
    public function addMany(Household $household, ?Member $author, array $entries): array
    {
        $results = [];

        foreach ($entries as $entry) {
            $label = trim($entry['label']);
            if ('' === $label) {
                continue;
            }

            $variant = $this->variants->resolve($household, null, $label, $entry['unit']);

            $existing = $this->items->findOneActiveByVariant($household, $variant);
            if (null !== $existing) {
                $results[] = $existing;
                continue;
            }

            $item = new ShoppingListItem($household, $label, $entry['unit'], $variant, $entry['quantity'], $author);
            $this->entityManager->persist($item);
            $results[] = $item;
        }

        $this->entityManager->flush();

        return $results;
    }
}
