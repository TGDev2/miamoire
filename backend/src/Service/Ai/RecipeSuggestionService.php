<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Enum\Equipment;
use App\Enum\FoodCut;
use App\Enum\FoodGroup;
use App\Enum\ProductTrait;
use App\Enum\Season;
use App\Exception\StockException;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Ai\Safety\DietaryGuardFactory;
use App\Service\Ai\Safety\RecipeSafetyFilter;
use App\Service\Inventory\InventoryAggregator;
use App\Service\Inventory\InventoryLine;
use App\Service\UnitConverter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génération de propositions culinaires à partir de l'inventaire réel.
 *
 * L'IA ne modifie JAMAIS le stock : elle renvoie une proposition de
 * consommation que l'utilisateur devra confirmer explicitement
 * (voir RecipeConfirmationService).
 *
 * Les exclusions alimentaires ne reposent plus sur la seule bonne volonté du
 * modèle. Elles sont appliquées deux fois, et cette redondance est délibérée :
 *
 * - **avant** — un aliment exclu ne figure pas dans l'inventaire transmis ;
 * - **après** — chaque recette renvoyée est relue, et celles qui nomment un
 *   aliment exclu sont écartées, y compris quand il n'apparaît qu'à l'étape 4.
 *
 * La consigne au modèle demeure : elle évite de gaspiller des propositions.
 * Mais c'est le contrôle qui garantit, pas la consigne.
 *
 * Le fournisseur de modèle est interchangeable — voir RecipeGeneratorFactory.
 */
class RecipeSuggestionService
{
    public function __construct(
        private readonly RecipeGeneratorFactory $generators,
        private readonly InventoryAggregator $aggregator,
        private readonly DietaryGuardFactory $guards,
        private readonly RecipeSafetyFilter $safetyFilter,
        private readonly RequiredIngredientFilter $requiredFilter,
        private readonly FoodGroupFilter $foodGroupFilter,
        private readonly AssumedCutFilter $assumedCutFilter,
        private readonly RecipePlanService $planner,
        private readonly SeasonalContextFactory $seasons,
        private readonly EntityManagerInterface $entityManager,
        private readonly UnitConverter $converter,
    ) {
    }

    /** @return array<string, mixed> */
    public function suggest(Household $household, RecipeRequest $request): array
    {
        // Lève une erreur explicite si aucune clé n'est configurée.
        $generator = $this->generators->create();

        [
            'guard' => $guard,
            'inventory' => $inventory,
            'required' => $required,
            'desired' => $desired,
            'payload' => $payload,
            'systemPrompt' => $systemPrompt,
        ] = $this->prepare($household, $request);
        $inventoryPayload = $payload['inventory'];

        $raw = $generator->generate(
            $systemPrompt."\nRéponds uniquement par le JSON demandé, sans texte d'introduction ni bloc de code.",
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            RecipeSchema::definition(),
        );

        try {
            /** @var array{recipes?: list<array<string, mixed>>} $decoded */
            $decoded = json_decode($this->stripCodeFence($raw), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new StockException('Réponse illisible du générateur de recettes. Réessayez.');
        }

        $checked = $this->safetyFilter->apply(
            array_slice($decoded['recipes'] ?? [], 0, 3),
            $guard,
            $this->labelsByKey($inventory),
        );

        // Second contrôle, de nature différente : l'aliment imposé. Il s'applique
        // à ce qui a survécu au premier — une recette écartée pour un allergène
        // n'a pas à être écartée une seconde fois pour un autre motif.
        $imposed = $this->requiredFilter->apply($checked['recipes'], $required);

        // Troisième contrôle, sur le même principe : la composition demandée.
        $constrained = $this->foodGroupFilter->apply($imposed['recipes'], $request->foodGroups);

        $rejected = array_merge($checked['rejected'], $imposed['rejected'], $constrained['rejected']);

        // Une liste vide sans explication laisserait croire à une panne. C'est au
        // contraire le contrôle qui a fonctionné : il faut le dire.
        if ([] === $constrained['recipes'] && [] !== $rejected) {
            throw new StockException(sprintf(
                'Les %d propositions reçues ont toutes été écartées (%s) : aucune ne vous est montrée. %s',
                count($rejected),
                implode(', ', array_unique(array_column($rejected, 'reason'))),
                $this->retryAdvice($constrained['rejected'], $request),
            ));
        }

        // Le plan de préparation est recalculé ici, sur ce qui va réellement être
        // montré — pas seulement filtré par mots-clés : la confirmation, plus
        // tard, revérifiera exactement le même document.
        $strictLabels = array_values(array_unique(array_merge(
            $household->getStrictExclusionLabels(),
            array_diff($request->exclusions, $household->getDietaryExclusionLabels()),
        )));

        $recipes = array_map(
            fn (array $recipe): array => $recipe + ['plan' => $this->planner->plan($guard, $strictLabels, $household, $recipe)],
            // Only real guesses are shown as "Forme supposée" — see AssumedCutFilter.
            $this->assumedCutFilter->apply($constrained['recipes'], $inventory),
        );

        // Seules les recettes réellement montrées nourrissent l'historique anti-
        // répétition : une proposition écartée par un filtre de sûreté n'a jamais
        // été vue, la compter empêcherait le modèle de la reproposer à tort.
        $shownNames = array_map(
            static fn (array $recipe): string => is_string($recipe['recipeName'] ?? null) ? $recipe['recipeName'] : '',
            $recipes,
        );
        $household->rememberRecipeSuggestions(array_values(array_filter(
            $shownNames,
            static fn (string $name): bool => '' !== $name,
        )));
        $this->entityManager->flush();

        return [
            'recipes' => $recipes,
            'rejected' => $rejected,
            'inventory' => $inventoryPayload,
            'guaranteedExclusions' => $guard->exclusions(),
            // `targetLabel` est la phrase que l'écran affiche telle quelle : la
            // même que celle du prompt et celle des motifs de rejet, pour que
            // trois écritures d'une seule quantité ne se contredisent jamais.
            'requiredIngredient' => null !== $required ? self::priorityResponse($required) : null,
            'desiredIngredients' => array_map(self::priorityResponse(...), $desired),
            'provider' => $generator->label(),
        ];
    }

    /**
     * La même demande, rédigée pour être confiée à la main à un autre assistant.
     *
     * Elle sert quand le générateur ne répond pas — quota, panne, aucune clé —
     * et n'en sollicite donc aucun. Consigne et données sont celles que
     * `suggest()` enverrait, à deux différences près, dites au modèle avant tout
     * le reste :
     *
     * - aucun programme ne relira sa réponse : les contrôles que la consigne
     *   annonce, c'est à lui de les faire. Le lui laisser croire serait lui
     *   apprendre qu'une contrainte non tenue passera (leçon 41) ;
     * - un humain lit la réponse : du texte, pas du JSON. Le format vient en
     *   dernier, après les données, pour être la dernière chose lue.
     *
     * Rien n'est écrit : une recette que l'application ne verra jamais n'a pas à
     * nourrir l'historique anti-répétition.
     */
    public function manualPrompt(Household $household, RecipeRequest $request): string
    {
        ['payload' => $payload, 'systemPrompt' => $systemPrompt] = $this->prepare($household, $request);
        $data = json_encode(self::markExpired($payload), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
            L'assistant culinaire de mon application de gestion de stock alimentaire ne répond
            pas. Je te transmets sa consigne et mes données pour que tu le remplaces.

            Trois consignes qui priment sur tout ce qui suit :
            - Aucun programme ne relira ta réponse, contrairement à ce qu'annonce la consigne.
              Les contrôles qu'elle décrit — exclusions alimentaires, aliment imposé et sa
              quantité, composition demandée — c'est toi qui les fais, sur chaque recette,
              avant de répondre. Plutôt qu'une recette qui en enfreint un, dis-moi ce qui manque.
            - Je lirai ta réponse moi-même : du texte, pas de JSON. La section « FORMAT DE
              RÉPONSE », à la fin, dit comment la présenter.
            - Tous les aliments de l'inventaire comptent, y compris ceux dont une part de
              `deadlines` porte `expired` à true : sa date `useBy` est passée. Seules ces
              parts-là sont périmées, ne compare pas les dates toi-même. Ils sont encore chez moi,
              au placard, au frigo ou au congélateur, et je veux les cuisiner. Une date
              dépassée n'écarte jamais un aliment : elle le rend prioritaire, avant même
              ceux qui périment bientôt. Passe en revue tout l'inventaire avant de choisir,
              aucune ligne n'est à ignorer. Chaque ingrédient que tu prends dans une part
              `expired`, même secondaire, et lui seulement, porte « date dépassée le … ». Si c'est une viande, un poisson ou
              un produit laitier frais qui n'est pas surgelé, ajoute de vérifier son aspect
              et son odeur avant de l'utiliser, et de le cuire à cœur. Pour un surgelé, une
              conserve ou un produit sec, n'ajoute rien d'autre que la date. Une part `frozen`
              est au congélateur : sa date n'est qu'une date de qualité, elle n'est jamais
              `expired` et ne porte aucune mention de date dépassée.

            ===== CONSIGNE DE L'APPLICATION =====

            {$systemPrompt}

            ===== DONNÉES =====

            {$data}

            ===== FORMAT DE RÉPONSE =====

            Ignore toute demande de JSON. Rédige les trois recettes en français, en texte
            lisible (Markdown accepté), l'une après l'autre. Les noms de champs cités dans la
            consigne désignent ces rubriques :

            - Titre (`recipeName`), puis le résumé (`summary`) en une ou deux phrases.
            - Faisabilité (`feasibility`) : « réalisable maintenant », « adaptée » ou
              « quelques courses ».
            - Portions (`servings`) et temps de préparation (`preparationTime`, en minutes).
            - Composantes (`foodGroups`) : féculent, légume, protéine, chacune avec
              l'ingrédient qui la fournit. Accompagnement ajouté (`includesSideDish`) : oui ou non.
            - Ingrédients de mon stock (`ingredientsFromInventory`), avec leur quantité ;
              « facultatif » pour ceux qui ne sont pas essentiels (`essential`) ; la forme
              supposée (`assumedCut`) entre parenthèses quand tu en as supposé une.
            - À acheter (`missingIngredients`), seulement s'il y en a.
            - Remplacements (`substitutions`), seulement s'il y en a.
            - Ustensiles (`utensils`).
            - Étapes (`steps`), numérotées.
            - À retirer du stock (`stockConsumptionProposal`) : chaque aliment de mon stock
              réellement consommé, nommé par son libellé `ingredient` — pas par sa clé — avec la
              quantité dans l'unité de l'inventaire. Je le déduirai moi-même.

            Après les trois recettes, termine par « Aliments à date dépassée non utilisés » :
            chaque aliment dont une part porte `expired` à true — ceux-là seulement — et
            qu'aucune des trois recettes n'emploie, avec pour chacun une idée rapide pour
            l'écouler. Écris
            « aucun » s'ils sont tous employés.
            PROMPT;
    }

    /**
     * Les parts d'inventaire dont la date est passée, marquées `expired`.
     *
     * L'export demande d'employer en priorité les aliments périmés. Laissé à la
     * comparaison de `useBy` avec `today`, le modèle annonçait « date dépassée »
     * sur une échéance de l'année suivante : c'est au serveur de le dire.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function markExpired(array $payload): array
    {
        $today = $payload['today'];

        $payload['inventory'] = array_map(
            static function (array $line) use ($today): array {
                // A frozen part's date is a quality limit: it never expires.
                $line['deadlines'] = array_map(
                    static fn (array $part): array => isset($part['useBy']) && $part['useBy'] < $today && !isset($part['frozen'])
                        ? $part + ['expired' => true]
                        : $part,
                    $line['deadlines'],
                );

                return $line;
            },
            $payload['inventory'],
        );

        return $payload;
    }

    /**
     * Ce que le modèle reçoit, quel qu'il soit : la consigne et ses données.
     *
     * Partagé par `suggest()` et `manualPrompt()` pour qu'un export ne puisse pas
     * dériver de ce que reçoit le générateur. Ne sollicite aucun générateur et
     * n'écrit rien.
     *
     * @return array{
     *     guard: Safety\DietaryGuard,
     *     inventory: list<InventoryLine>,
     *     required: ?ResolvedPriority,
     *     desired: list<ResolvedPriority>,
     *     payload: array<string, mixed>,
     *     systemPrompt: string,
     * }
     */
    private function prepare(Household $household, RecipeRequest $request): array
    {
        $guard = $this->guards->for($household, $request->exclusions);
        $inventory = $this->aggregator->aggregate($household, guard: $guard);

        if ([] === $inventory) {
            throw new StockException($guard->isEmpty()
                ? 'Votre inventaire est vide : ajoutez au moins un aliment avant de demander une recette.'
                : 'Aucun aliment de votre inventaire ne respecte les exclusions du foyer.');
        }

        ['required' => $required, 'desired' => $desired] = $this->resolvePriorities($inventory, $request->priorities);

        // Le seul endroit où l'heure est lue : le reste du service la reçoit, ce
        // qui rend la saison — et donc le registre des plats — testable.
        $season = $this->seasons->for(new \DateTimeImmutable('today'));

        $payload = [
            ...$season->toAiPayload(),
            'people' => $request->people,
            'mealType' => $request->mealType,
            'mealTemperature' => $request->mealTemperature?->value,
            'maxPreparationMinutes' => $request->maxMinutes,
            // Les libellés, pas les valeurs techniques : la règle 12 impose que
            // chaque `utensils` appartienne à cette liste, et le modèle la recopie
            // telle quelle. « stovetop » s'affichait alors en anglais sur la fiche
            // d'une recette française.
            'availableEquipment' => array_map(
                static fn (Equipment $case): string => $case->label(),
                $request->equipment,
            ),
            'dietaryExclusions' => $guard->exclusions(),
            'allowExtraIngredients' => $request->allowExtraIngredients,
            'mustUseIngredient' => null !== $required ? self::priorityPayload($required) : null,
            'desiredIngredients' => array_map(self::priorityPayload(...), $desired),
            'requiredFoodGroups' => array_map(
                static fn (FoodGroup $group): string => $group->value,
                $request->foodGroups,
            ),
            'inventory' => array_map(static fn (InventoryLine $line) => $line->toAiPayload(), $inventory),
        ];

        return [
            'guard' => $guard,
            'inventory' => $inventory,
            'required' => $required,
            'desired' => $desired,
            'payload' => $payload,
            'systemPrompt' => $this->systemPrompt(
                $guard->exclusions(),
                $required,
                $desired,
                $household->getRecentRecipeSuggestions(),
                $season,
                $request->equipment,
                $request->foodGroups,
            ),
        ];
    }

    /**
     * Ce que le modèle lit d'un aliment privilégié.
     *
     * @return array<string, mixed>
     */
    private static function priorityPayload(ResolvedPriority $priority): array
    {
        $payload = ['key' => $priority->line->key, 'ingredient' => $priority->line->label];

        if ($priority->hasTarget()) {
            $payload['targetQuantity'] = $priority->quantity;
            $payload['targetUnit'] = $priority->unit->value;
        }

        return $payload;
    }

    /**
     * Ce que l'écran lit d'un aliment privilégié.
     *
     * @return array<string, mixed>
     */
    private static function priorityResponse(ResolvedPriority $priority): array
    {
        return [
            'key' => $priority->line->key,
            'label' => $priority->line->label,
            'targetQuantity' => $priority->quantity,
            'targetUnit' => $priority->unit?->value,
            'targetLabel' => $priority->describe(),
        ];
    }

    /**
     * Les aliments à privilégier, résolus contre l'inventaire, dans l'ordre.
     *
     * Le PREMIER est une contrainte, exactement comme l'ancien `priorityKey` :
     * une clé inconnue est une erreur explicite et non un silence, parce
     * qu'elle vient d'un inventaire que l'application affichait encore alors
     * que le lot venait d'être épuisé ou écarté par une exclusion — générer
     * trois recettes en ignorant la contrainte serait la pire des réponses.
     *
     * Les suivants (au plus 2) ne sont que des préférences : une clé devenue
     * invalide y est silencieusement ignorée plutôt que de faire échouer toute
     * la génération pour un aliment facultatif par nature.
     *
     * La quantité éventuellement demandée sur chacun est résolue ici aussi —
     * exemplaires convertis, unité vérifiée, cible plafonnée au stock : voir
     * {@see ResolvedPriority}.
     *
     * @param list<InventoryLine>  $inventory
     * @param list<PriorityChoice> $priorities
     *
     * @return array{required: ?ResolvedPriority, desired: list<ResolvedPriority>}
     */
    private function resolvePriorities(array $inventory, array $priorities): array
    {
        // Dédoublonnage sur la clé : deux quantités contradictoires pour un même
        // aliment n'ont pas de sens, la première saisie fait foi.
        $choices = [];
        foreach ($priorities as $choice) {
            $key = trim($choice->key);

            if ('' !== $key && !isset($choices[$key])) {
                $choices[$key] = $choice;
            }
        }

        $choices = array_values($choices);

        if ([] === $choices) {
            return ['required' => null, 'desired' => []];
        }

        $byKey = [];
        foreach ($inventory as $line) {
            $byKey[$line->key] = $line;
        }

        $first = array_shift($choices);
        $requiredLine = $byKey[trim($first->key)] ?? throw new StockException(
            'L\'aliment à utiliser en priorité n\'est plus disponible dans votre inventaire. '
            .'Rafraîchissez l\'écran et choisissez-en un autre.',
        );

        $desired = [];
        foreach (array_slice($choices, 0, 2) as $choice) {
            $line = $byKey[trim($choice->key)] ?? null;

            if (null !== $line) {
                $desired[] = ResolvedPriority::resolve($choice, $line, $this->converter);
            }
        }

        return [
            'required' => ResolvedPriority::resolve($first, $requiredLine, $this->converter),
            'desired' => $desired,
        ];
    }

    /**
     * @param list<InventoryLine> $inventory
     *
     * @return array<string, string>
     */
    private function labelsByKey(array $inventory): array
    {
        $labels = [];

        foreach ($inventory as $line) {
            $labels[$line->key] = $line->label;
        }

        return $labels;
    }

    /**
     * Le glossaire est construit depuis l'enum : ajouter un trait suffit à
     * l'enseigner au modèle, sans toucher au prompt.
     */
    private function traitGlossary(): string
    {
        return implode("\n", array_map(
            static fn (ProductTrait $trait) => sprintf('- `%s` (%s) : %s.', $trait->value, $trait->label(), $trait->instruction()),
            ProductTrait::cases(),
        ));
    }

    /** Même principe pour les découpes : la cuisson de chaque forme est dans l'enum. */
    private function cutGlossary(): string
    {
        return implode("\n", array_map(
            static fn (FoodCut $cut) => sprintf('- `%s` (%s) : %s.', $cut->value, $cut->label(), $cut->instruction()),
            FoodCut::cases(),
        ));
    }

    /**
     * Ce que permet chaque équipement coché — et lui seul.
     *
     * Décrire les neuf appareils quand le foyer n'en a coché aucun allongerait la
     * consigne sans rien apprendre : un bloc vide est la bonne réponse.
     *
     * @param list<Equipment> $equipment
     */
    private function equipmentGlossary(array $equipment): string
    {
        if ([] === $equipment) {
            return '';
        }

        return sprintf(
            "\n            ÉQUIPEMENTS DISPONIBLES — ce que chacun permet, et ce qu'il interdit :\n\n%s\n",
            implode("\n", array_map(
                // Nommés par leur libellé, comme dans `availableEquipment` : c'est
                // ce mot-là que `utensils` doit reprendre.
                static fn (Equipment $case): string => sprintf(
                    '            - %s : %s.',
                    $case->label(),
                    $case->instruction(),
                ),
                $equipment,
            )),
        );
    }

    /**
     * L'invitation à sortir le barbecue, quand la saison s'y prête.
     *
     * Une incitation, jamais une interdiction symétrique : un foyer qui coche le
     * barbecue en janvier sait ce qu'il fait, et rien ici ne le lui refuse.
     *
     * @param list<Equipment> $equipment
     */
    private function outdoorInvitation(array $equipment, SeasonalContext $season): string
    {
        $outdoor = array_values(array_filter($equipment, static fn (Equipment $case) => $case->isOutdoor()));

        if ([] === $outdoor || Season::Summer !== $season->season) {
            return '';
        }

        return sprintf(
            "\n            Nous sommes en été et le foyer dispose de : %s. Au moins une des trois\n"
            ."            propositions peut s'en servir, si l'inventaire s'y prête.\n",
            implode(', ', array_map(static fn (Equipment $case) => $case->label(), $outdoor)),
        );
    }

    /**
     * La quantité à écouler, dite au modèle — vide quand aucune n'est imposée.
     *
     * « Quitte à ajuster les portions » n'est pas une politesse : sans cette
     * phrase, le modèle tient le nombre de convives pour intouchable, met
     * 100 g de l'aliment imposé dans une garniture, et le lot périme quand même.
     * Or c'est exactement ce que l'utilisateur cherchait à éviter en ouvrant cet
     * écran.
     */
    private function quantityInstruction(ResolvedPriority $required): string
    {
        $target = $required->describe();

        if (null === $target) {
            return '';
        }

        return sprintf(
            "\n            Il faut en écouler AU MOINS %s : dimensionne la recette pour l'atteindre,\n"
            ."            quitte à ajuster le nombre de portions et à prévoir un reste. Le même\n"
            ."            programme relit la quantité proposée pour cette clé dans\n"
            ."            `stockConsumptionProposal` et supprime les recettes qui restent nettement\n"
            .'            en dessous.',
            $target,
        );
    }

    /**
     * Que faire quand toutes les propositions ont été écartées.
     *
     * Relancer suffit d'ordinaire. Pas quand c'est la composition qui manque :
     * un inventaire sans aucune protéine en donnera aussi peu à la génération
     * suivante, et le conseil enverrait l'utilisateur tourner en rond.
     *
     * @param list<array{recipeName: string, reason: string}> $compositionRejections
     */
    private function retryAdvice(array $compositionRejections, RecipeRequest $request): string
    {
        if ([] === $compositionRejections) {
            return 'Relancez une génération.';
        }

        return $request->allowExtraIngredients
            ? 'Votre inventaire n\'en contient peut-être pas : décochez ce groupe dans « À inclure dans le plat ».'
            : 'Votre inventaire n\'en contient peut-être pas : décochez ce groupe dans « À inclure dans le plat », ou autorisez quelques ingrédients manquants.';
    }

    /** Certains modèles encadrent le JSON par ```json malgré la consigne. */
    private function stripCodeFence(string $raw): string
    {
        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        }

        return trim($trimmed);
    }

    /**
     * La consigne commune, sans format de réponse : c'est l'appelant qui le
     * fixe — JSON pour le générateur, texte lisible pour un export.
     *
     * @param list<string>           $exclusions
     * @param list<ResolvedPriority> $desired
     * @param list<string>           $recentNames
     * @param list<Equipment>        $equipment
     * @param list<FoodGroup>        $foodGroups
     */
    private function systemPrompt(
        array $exclusions,
        ?ResolvedPriority $required,
        array $desired,
        array $recentNames,
        SeasonalContext $season,
        array $equipment,
        array $foodGroups,
    ): string {
        $units = implode(', ', array_map(static fn (\App\Enum\Unit $u) => $u->value, \App\Enum\Unit::cases()));
        $traits = $this->traitGlossary();
        $cuts = $this->cutGlossary();
        $today = $season->todayLabel();
        $seasonLabel = $season->season->label();
        $seasonGuidance = $season->season->guidance();
        $gear = $this->equipmentGlossary($equipment).$this->outdoorInvitation($equipment, $season);

        $imposed = null === $required
            ? ''
            : sprintf(
                "\n            ALIMENT IMPOSÉ : « %s » (clé `%s`). Les TROIS recettes doivent l'employer,\n"
                ."            et sa clé doit figurer dans leur `stockConsumptionProposal`. Un programme\n"
                ."            vérifie cette clé sur chaque recette et supprime celles qui ne l'utilisent\n"
                ."            pas : une proposition sans cet aliment est une proposition perdue.%s\n",
                $required->line->label,
                $required->line->key,
                $this->quantityInstruction($required),
            );

        $wished = [] === $desired
            ? ''
            : sprintf(
                "\n            ALIMENTS SOUHAITÉS (non obligatoires) : %s. Utilise-les quand c'est\n"
                ."            culinairement cohérent, sans jamais forcer un plat qui n'aurait pas de sens.\n"
                ."            Contrairement à l'aliment imposé, une recette qui les ignore n'est PAS\n"
                ."            rejetée : indique simplement leur clé dans `stockConsumptionProposal` ou\n"
                ."            `ingredientsFromInventory` quand tu les utilises réellement.\n",
                implode(', ', array_map(
                    static function (ResolvedPriority $priority): string {
                        $target = $priority->describe();

                        return sprintf(
                            '« %s » (clé `%s`%s)',
                            $priority->line->label,
                            $priority->line->key,
                            null !== $target ? sprintf(', idéalement %s', $target) : '',
                        );
                    },
                    $desired,
                )),
            );

        $composition = [] === $foodGroups
            ? ''
            : sprintf(
                "\n            COMPOSITION DEMANDÉE : chacune des TROIS recettes doit contenir %s.\n"
                ."            Prends-les dans l'inventaire ; un ingrédient manquant ne peut fournir un\n"
                ."            groupe que si `allowExtraIngredients` est true (règle 3). Ils font partie\n"
                ."            du plat lui-même, avec leurs quantités et leurs étapes, et l'ingrédient\n"
                ."            qui fournit chaque groupe est `essential: true` — un légume facultatif\n"
                ."            n'est pas un légume garanti. Un programme relit `foodGroups` sur chaque recette\n"
                ."            et supprime celles où l'un de ces groupes manque. Il connaît les aliments\n"
                ."            courants : une déclaration fausse — du riz rangé parmi les protéines —\n"
                ."            est détectée et traitée comme un oubli. Si l'inventaire ne fournit pas\n"
                ."            un groupe, ne maquille rien : déclare honnêtement ce que le plat contient,\n"
                ."            l'utilisateur sera prévenu et pourra changer sa demande.\n",
                implode(', ', array_map(static fn (FoodGroup $group): string => $group->definition(), $foodGroups)),
            );

        $safety = [] === $exclusions
            ? ''
            : sprintf(
                "\n            CONTRÔLE AUTOMATIQUE : chaque recette que tu renvoies est relue par un\n"
                ."            programme qui cherche %s dans son titre, son résumé, ses ingrédients,\n"
                ."            ses substitutions ET chacune de ses étapes. Toute recette où l'un de ces\n"
                ."            termes apparaît est supprimée avant d'atteindre l'utilisateur, sans appel.\n"
                ."            Une recette écartée est une proposition perdue : n'en propose aucune qui\n"
                ."            s'en approche, y compris en accompagnement ou en suggestion de service.\n",
                implode(', ', $exclusions),
            );

        $recentAvoidance = [] === $recentNames
            ? ''
            : sprintf(
                "\n            PLATS RÉCEMMENT PROPOSÉS À CE FOYER (du plus ancien au plus récent) : %s.\n"
                ."            N'en propose aucun à l'identique, et évite aussi leur famille culinaire\n"
                ."            immédiate — un gratin dauphinois après une tartiflette ou une croziflette\n"
                ."            est un doublon, pas une nouveauté. Un ingrédient qui revient (le fromage,\n"
                ."            par exemple) ne doit pas systématiquement mener au même type de plat :\n"
                ."            envisage d'autres formats (tarte salée, poêlée, risotto, feuilleté, salade\n"
                ."            tiède, soupe, croque, gratin seulement si aucune autre idée ne convient\n"
                ."            vraiment mieux) avant de répéter une recette de cette liste.\n",
                implode(', ', array_map(static fn (string $name): string => sprintf('« %s »', $name), $recentNames)),
            );

        return <<<PROMPT
            Tu es un assistant culinaire intégré à une application de gestion de stock alimentaire.
            Tu proposes exactement 3 recettes réalisables à partir d'un inventaire réel, en français.

            Données reçues : la date du jour et la saison qu'elle désigne, le nombre de
            personnes, le type de repas, une préférence chaude ou froide, le temps
            disponible, les équipements utilisables, les exclusions alimentaires,
            l'autorisation ou non d'ajouter des ingrédients manquants, les composantes
            exigées dans chaque plat (`requiredFoodGroups`, souvent vide), et
            l'inventaire du foyer.

            Nous sommes le {$today}, donc en {$seasonLabel}. C'est la référence de toutes
            les dates que tu liras : une échéance `useBy` ne veut rien dire sans elle.

            Chaque ligne d'inventaire porte une clé stable `key`, un libellé, une quantité,
            une unité et `deadlines` : la même quantité ventilée par échéance effective,
            chaque part portant sa propre quantité et, si présents, `useBy`, `opened`
            (exemplaire entamé, ouvert ou refermé), `expiresSoon` et `frozen`. Une part
            `frozen` est au congélateur : son `useBy` est une date de QUALITÉ, pas de
            sécurité, car un aliment congelé reste sain ; `pastBest` y signale un aliment
            congelé depuis plus longtemps que conseillé, encore sain, à écouler sans
            urgence. Une recette qui emploie une part `frozen` prévoit sa décongélation,
            la veille au réfrigérateur, ou une cuisson directe quand l'aliment s'y prête.
            Une ligne dont
            `deadlines` compte plusieurs parts a des lots aux échéances différentes —
            250 g à utiliser demain, 1 kg scellé plus tard, par exemple : ne traite jamais
            la quantité totale comme uniformément urgente dans ce cas. Elle peut aussi
            porter `traits`, `category`, `notes`, `quantityIsEstimated` et
            `expirationIsEstimated`, qui décrivent l'état réel de l'aliment et la confiance
            qu'on peut lui accorder.

            `perishable` à true signale un produit frais, sans emballage qui le protège.
            `approxWeightPerPiece`, quand elle est présente, donne le poids indicatif
            d'une pièce : sers-t'en pour écrire des quantités lisibles dans tes étapes
            (« 2 courgettes, environ 500 g ») — mais `stockConsumptionProposal` doit
            rester exprimée dans l'unité de l'inventaire, ici en pièces. Ne convertis
            JAMAIS une masse en pièces dans la proposition de déduction.

            `cut` donne la FORME sous laquelle l'aliment se présente, et c'est elle qui
            décide de la cuisson : une escalope se saisit trois minutes par face quand un
            blanc entier en cuit vingt. Absente, la forme est INCONNUE — jamais « entière ».

            Signification des `traits` :

            {$traits}

            Signification des `cut` :

            {$cuts}

            Règles impératives :

            1. Priorise les ingrédients dont au moins une part de `deadlines` a
               `expiresSoon` à true : ils doivent apparaître dans au moins deux des trois
               propositions quand c'est culinairement cohérent, et la quantité utilisée doit
               d'abord venir de cette part-là.
               Viennent ensuite les ingrédients marqués `perishable` : ce sont des produits
               frais — légumes, fruits, viande au détail, pain, restes — qui se dégradent
               dès l'achat, là où une conserve ou un paquet sec attend des mois. Tant qu'il
               en reste en stock, au moins deux des trois recettes doivent s'appuyer sur
               l'un d'eux, et tu ne dois jamais choisir un équivalent en conserve, sec ou
               surgelé quand le frais correspondant est disponible.
               Puis seulement, à égalité, ceux dont une part a `opened` à true : un paquet
               entamé se dégrade pendant qu'un paquet scellé attend. La date passe toujours
               devant, et le frais passe devant l'entamé.
            2. Classe chaque recette par `feasibility` :
               - "ready" : tous les ingrédients essentiels sont dans l'inventaire ;
               - "adapted" : tu remplaces un ingrédient absent par un autre réellement disponible
                 (décris chaque remplacement dans `substitutions`) ;
               - "needs_shopping" : il manque un ou deux ingrédients clairement identifiés.
            3. Si `allowExtraIngredients` est false, ne propose aucune recette "needs_shopping" :
               `missingIngredients` doit rester vide. Les condiments de base présents dans
               toute cuisine (sel, poivre, eau) ne comptent jamais comme manquants.
            4. Distingue toujours l'essentiel du facultatif via le champ `essential`.
               L'absence de persil ne rend pas une recette impossible : marque-la `essential: false`.
            5. Adapte impérativement les quantités au nombre de personnes demandé et renseigne
               `servings` avec ce même nombre.
            6. Respecte `maxPreparationMinutes` et n'utilise que les équipements listés
               quand la liste n'est pas vide.
            7. N'utilise jamais un ingrédient figurant dans `dietaryExclusions`, ni aucun de ses
               dérivés : pas de beurre de cacahuète si l'arachide est exclue, pas de semoule si
               le gluten l'est.
            8. Les `traits` et les `notes` décrivent l'aliment tel qu'il est réellement dans
               le placard. N'écris JAMAIS une étape de préparation déjà faite : des pommes de
               terre `canned` ne s'épluchent pas et ne se cuisent pas trente minutes, elles
               s'égouttent et se réchauffent. Adapte aussi le temps de préparation en
               conséquence.
            9. `quantityIsEstimated` signale une quantité approximative : ne bâtis pas une
               recette qui exigerait cette quantité au gramme près, et reste en deçà.
            10. Un plat principal qui ne constitue pas un repas complet à lui seul (une
                viande, un poisson ou une sauce sans base féculente ni légume, par exemple)
                DOIT être complété par un accompagnement (féculent, légume, salade…) intégré
                À LA MÊME recette — ses ingrédients, ses quantités et son étape de
                préparation font partie de `ingredientsFromInventory`/`missingIngredients`/
                `steps` de cette recette, ce n'est jamais une recette séparée. N'en invente
                aucun pour un plat déjà complet (une quiche, un curry déjà servi avec son
                riz, une salade composée). `includesSideDish` doit refléter honnêtement ce
                choix : true seulement si tu as effectivement ajouté un accompagnement.
            11. `mealTemperature` vaut "hot", "cold", ou est absente. À "hot", ne propose que
                des plats servis chauds. À "cold", ne propose que des plats froids ou à
                température ambiante — rien qui exige une cuisson juste avant de servir.
                Absente : aucune contrainte de température.
            12. `utensils` liste les ustensiles et équipements réellement nécessaires à CETTE
                recette précise, jamais un terme générique : « poêle antiadhésive 26 cm »,
                « plat à gratin », « four à 200°C », « fouet », « mixeur plongeant », pas
                « un plat » ni « des ustensiles de cuisine ». Si `availableEquipment` n'est
                pas vide, chaque élément de `utensils` doit lui appartenir (règle 6).
            13. Les trois recettes renvoyées doivent différer entre elles de technique et de
                format (four/poêle/cocotte/cru/vapeur, plat unique/tarte/soupe/salade…) :
                jamais trois variantes du même plat, même avec des ingrédients différents.
            14. Rédige des étapes précises et actionnables : température du four en °C,
                durée de cuisson ou de repos, taille de découpe, indice visuel de cuisson.
                Une étape comme « faites cuire » sans aucune de ces précisions n'est pas
                assez concrète.
            15. La forme d'un aliment ne s'invente JAMAIS. Quand `cut` est présent, il
                fait loi : `strips` ne devient pas une escalope, `slices` ne se retaille
                pas, `ground` ne se grille pas en pièce. Quand `cut` est absent, tu as
                deux issues, et une seule interdiction — supposer en silence :
                - écrire l'étape de façon valable pour toute forme (« faites cuire le
                  blanc de poulet jusqu'à ce qu'il ne soit plus rosé à cœur ») ;
                - ou bâtir la recette sur une forme précise, et la DÉCLARER dans le champ
                  `assumedCut` de cet ingrédient. L'utilisateur la verra et pourra la
                  corriger en un geste ; l'application s'en souviendra pour toujours.
                `assumedCut` désigne la forme sous laquelle l'aliment a été ACHETÉ et
                rangé, jamais celle que ta recette lui donne : couper des carottes
                entières en dés est une étape, pas une forme supposée « cubes ».
                `assumedCut` vaut "none" dès que la ligne porte un `cut` : tu n'as alors
                rien supposé.
            16. `today` est la date du jour. Toutes les dates `useBy` s'y rapportent :
                une échéance d'aujourd'hui ou de demain est urgente même sans
                `expiresSoon`, une échéance dépassée doit être signalée plutôt qu'ignorée.
                Sauf sur une part `frozen` : sa date n'est jamais urgente ni périmée.
            17. `season` donne la saison, et c'est le REGISTRE des plats qu'elle règle,
                jamais leur contenu : en {$seasonLabel}, privilégie {$seasonGuidance}.
                `seasonalProduce` liste les fruits et légumes réellement de saison ce
                mois-ci : quand `allowExtraIngredients` est vrai, les `missingIngredients`
                en viennent en priorité. On ne fait pas acheter une fraise en décembre.
            18. GARDE-FOU, qui prime sur la règle 17 : l'inventaire l'emporte TOUJOURS sur
                la saison. Une courge encore en stock en juillet se cuisine, une salade
                achetée en janvier se mange. La saison oriente ce qu'on choisit parmi
                plusieurs possibilités et ce qu'on ajoute à la liste de courses ; elle ne
                justifie jamais de laisser périmer un aliment déjà là. La règle 1
                l'emporte sur la 17 chaque fois que les deux se contredisent.
            19. `foodGroups` déclare les composantes que le plat contient réellement,
                chacune avec l'ingrédient de la recette qui la fournit (son `label`,
                recopié à l'identique) :
                "starch" pour un féculent, "vegetable" pour un légume, "protein" pour une
                protéine. La pomme de terre est un féculent, pas un légume ; les
                légumineuses (lentilles, pois chiches, haricots secs) comptent comme
                féculent et comme protéine. Les céréales — riz, pâtes, pain, semoule — ne
                sont JAMAIS des protéines, même si elles en contiennent un peu. N'y mets
                que ce qui est vraiment dans l'assiette, une liste vide si rien ne s'y prête.
            {$gear}{$imposed}{$wished}{$composition}{$safety}{$recentAvoidance}
            À propos de `stockConsumptionProposal` :

            - C'est une PROPOSITION de retrait de stock, soumise ensuite à validation humaine.
            - Le champ `key` doit reprendre EXACTEMENT une clé `key` de l'inventaire reçu.
              N'invente jamais de clé et n'y mets jamais un ingrédient à acheter.
            - Une clé désigne un aliment DANS UN ÉTAT PRÉCIS : `tomate` et `tomate-conserve`
              sont deux stocks différents, jamais interchangeables.
            - `quantity` ne doit jamais dépasser la quantité disponible pour cette clé.
            - `unit` doit appartenir à : {$units}, et rester compatible avec l'unité
              de l'inventaire (une masse ne devient pas un volume).
            - N'y fais figurer chaque clé qu'UNE SEULE FOIS, en cumulant les usages de la
              recette : deux lignes pour le même ingrédient seraient fusionnées de toute façon.
            - N'y fais figurer que ce qui est réellement consommé par la recette.

            Rédige des étapes concises mais concrètes (voir règle 14).
            PROMPT;
    }
}
