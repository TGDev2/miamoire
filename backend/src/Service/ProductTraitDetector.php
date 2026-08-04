<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ProductTrait;

/**
 * Déduit l'état d'un produit de son nom et de sa catégorie.
 *
 * Volontairement conservateur : un faux positif égare le générateur de recettes
 * aussi sûrement qu'une absence d'information. On ne déduit un trait que si le
 * libellé le dit explicitement — jamais par famille d'aliment.
 *
 * Une seule implication est admise, parce qu'elle est vraie sans exception :
 * l'appertisation cuit son contenu.
 */
class ProductTraitDetector
{
    /**
     * Expressions cherchées dans le libellé normalisé (sans accent, minuscules).
     * Les frontières de mot évitent « biscuit » → cuit ou « sucre » → sucré.
     *
     * @var array<value-of<ProductTrait>, list<string>>
     */
    private const PATTERNS = [
        // « boîte » seul est écarté : une « boîte de 6 œufs » n'est pas une conserve.
        'canned' => ['\bconserve\b', '\bconserves\b', '\bappertise', '\bbocal\b', '\ben boite\b'],
        'frozen' => ['\bsurgele', '\bcongele'],
        'dried' => ['\bdeshydrate', '\blyophilise', '\bseche\b', '\bseches\b'],
        'peeled' => ['\bepluche', '\bpele\b', '\bpeles\b', '\bpelee', '\bsans peau\b', '\bemonde'],
        'precooked' => ['\bprecuit', '\bpre cuit', '\bmi cuit'],
        // « rôti » est écarté : un rôti de porc cru en porte le nom.
        'cooked' => ['\bcuit\b', '\bcuits\b', '\bcuite\b', '\bcuites\b', '\bcuisine\b', '\bcuisines\b'],
        'ready_to_eat' => ['\bpret a consommer\b', '\bpret a l emploi\b', '\ba rechauffer\b'],
        // « en morceaux », « émincé », « râpé » ne sont plus ici : ils décrivent
        // une FORME, et {@see \App\Service\Food\FoodCutDetector} la nomme
        // précisément au lieu de la réduire à « coupé ».
        // « sucre » seul est écarté : un paquet de sucre n'est pas un produit sucré.
        'sweetened' => ['\bsucree', '\bau sirop\b', '\bsucres ajoutes\b'],
        'unsweetened' => ['\bsans sucre', '\bnon sucre'],
    ];

    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    /** @return list<ProductTrait> */
    public function detect(string $name, ?string $category = null): array
    {
        $haystack = $this->normalizer->toWords(trim($name.' '.($category ?? '')));

        $traits = [];
        foreach (self::PATTERNS as $value => $patterns) {
            foreach ($patterns as $pattern) {
                if (1 === preg_match('/'.$pattern.'/', $haystack)) {
                    $traits[] = ProductTrait::from($value);
                    continue 2;
                }
            }
        }

        // « sans sucre » contient « sucre » : la mention négative l'emporte toujours.
        if (in_array(ProductTrait::Unsweetened, $traits, true)) {
            $traits = array_values(array_filter($traits, static fn (ProductTrait $t) => ProductTrait::Sweetened !== $t));
        }

        // Une conserve est cuite dans sa boîte, sans exception.
        if (in_array(ProductTrait::Canned, $traits, true) && !in_array(ProductTrait::Precooked, $traits, true)) {
            $traits[] = ProductTrait::Precooked;
        }

        // « précuit » est plus précis que « cuit » : garder les deux brouillerait la consigne.
        if (in_array(ProductTrait::Precooked, $traits, true)) {
            $traits = array_values(array_filter($traits, static fn (ProductTrait $t) => ProductTrait::Cooked !== $t));
        }

        return $traits;
    }
}
