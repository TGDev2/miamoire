<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\FoodCut;
use App\Service\TextNormalizer;

/**
 * Déduit la forme d'un aliment de son libellé — « Escalopes de poulet »,
 * « Blanc de poulet en tranches ».
 *
 * Deux règles gouvernent ce détecteur, et la seconde est celle qui compte.
 *
 * **Le premier motif reconnu gagne**, dans l'ordre de déclaration : du plus
 * spécifique au plus général. « Steaks hachés » est du haché, pas un pavé.
 *
 * **Une forme est ensuite confrontée à {@see FoodCutLexicon}**, et abandonnée si
 * l'aliment ne peut pas la prendre. C'est ce garde-fou qui empêche « lait entier »
 * de devenir une pièce entière et « steak de soja » de devenir du haché : le mot
 * est bien là, mais l'aliment n'a pas de découpe. Sans lui, la liste de motifs
 * aurait dû être vérifiée aliment par aliment (leçon n° 2) au lieu de l'être une
 * fois pour toutes.
 *
 * Muet par défaut, comme {@see \App\Service\ProductTraitDetector} et
 * {@see FoodNatureDetector} : une découpe fausse égare la cuisson plus sûrement
 * qu'une découpe absente, et la correction reste à un appui.
 */
class FoodCutDetector
{
    /**
     * Motifs sans accent et en minuscules : ils sont confrontés au texte déjà
     * passé par {@see TextNormalizer::toWords()}.
     *
     * Les termes anglais valent pour les catégories, qui arrivent d'Open Food
     * Facts dans leur taxonomie d'origine (leçon n° 31).
     *
     * @var array<value-of<FoodCut>, list<string>>
     */
    private const PATTERNS = [
        // « steak haché » avant « steak » : le premier est du haché, le second un pavé.
        'ground' => ['\bhachee?s?\b', '\bground\b', '\bminced\b'],
        'bone_in' => [
            '\bpilons?\b', '\bcuisses?\b', '\bmanchons?\b', '\bcotelettes?\b',
            '\bjarrets?\b', '\bsouris d agneau\b', '\bbone in\b', '\bdrumsticks?\b',
        ],
        'escalope' => ['\bescalopes?\b', '\bpaillards?\b', '\bcutlets?\b'],
        'strips' => ['\bemince(?:s|e|es)?\b', '\baiguillettes?\b', '\blanieres?\b', '\bstrips\b'],
        'grated' => ['\brapee?s?\b', '\bgrated\b'],
        // « tranche » au singulier est écarté : une tranche napolitaine est une
        // glace, un saumon tranché reste muet plutôt que faux.
        'slices' => ['\ben tranches?\b', '\btranches\b', '\brondelles?\b', '\bsliced\b', '\bslices\b'],
        // « cube » seul nommerait un bouillon : seul « en cubes » est une découpe.
        'cubes' => ['\ben des\b', '\ben cubes?\b', '\bdiced\b'],
        'pieces' => ['\ben morceaux\b', '\bconcasse(?:s|e|es)?\b', '\bchunks?\b'],
        // Après les formes explicites : « pulpe de tomates EN DÉS » nomme une
        // famille de produit puis sa découpe, et c'est la seconde qui décrit ce
        // qu'on verra dans la boîte.
        'puree' => ['\bpulpes?\b', '\bpurees?\b', '\bcoulis\b'],
        // « pavé » et « côte » ne valent que suivis de l'aliment : un pavé
        // d'Affinois est un fromage, une côte de blette une tige.
        'steak' => [
            '\bpaves? de\b', '\bcotes? de (?:porc|boeuf|veau|agneau|mouton)\b',
            '\bsteaks? de (?:boeuf|thon|saumon)\b',
        ],
        'whole' => ['\bentiers?\b', '\bentieres?\b', '\bwhole\b'],
    ];

    /**
     * Les formes dont le vocabulaire ne veut rien dire hors d'un aliment connu.
     *
     * « Entier » qualifie un lait, un blé, un œuf, un grain de café aussi souvent
     * qu'un poulet : sans aliment reconnu devant lui, il ne décrit aucune découpe.
     * Les autres se suffisent — une escalope, un pilon, un émincé, un râpé sont
     * des formes où qu'ils apparaissent, y compris sur un produit que le lexique
     * ne connaît pas encore.
     *
     * @var list<value-of<FoodCut>>
     */
    private const REQUIRES_KNOWN_FOOD = ['whole'];

    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly FoodCutLexicon $lexicon,
    ) {
    }

    public function detect(string $name, ?string $category = null): ?FoodCut
    {
        $plausible = $this->lexicon->plausibleCuts($name);

        // Une soupe, un gratin, un reste : reconnus, et sans découpe possible.
        if ([] === $plausible) {
            return null;
        }

        $haystack = $this->normalizer->toWords(trim($name.' '.($category ?? '')));

        foreach (self::PATTERNS as $value => $patterns) {
            // Le mot est peut-être là, mais l'aliment ne prend pas cette forme :
            // on ne lit même pas ses motifs.
            if (null !== $plausible && !in_array(FoodCut::from($value), $plausible, true)) {
                continue;
            }

            if (null === $plausible && in_array($value, self::REQUIRES_KNOWN_FOOD, true)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (1 === preg_match('/'.$pattern.'/', $haystack)) {
                    return FoodCut::from($value);
                }
            }
        }

        return null;
    }
}
