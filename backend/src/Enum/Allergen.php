<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les allergènes et régimes reconnus, avec leurs produits dérivés.
 *
 * Écrire « ne propose aucune recette avec des arachides » dans une consigne n'est
 * pas une garantie : un modèle peut l'ignorer, et il suffit qu'il l'ignore une fois.
 * Une exclusion doit donc être **vérifiable** — d'où cette table, qui transforme un
 * mot saisi par l'utilisateur en une liste de termes cherchés dans chaque recette.
 *
 * Un allergène ne se nomme presque jamais par son nom : personne n'écrit
 * « arachide » dans une recette, on y lit « beurre de cacahuète » ou « satay ».
 * C'est cette traduction qui fait tout le travail.
 *
 * Deux pièges symétriques, tous deux couverts par `exceptions()` :
 *
 * - le faux négatif — « cacahuète » non reconnu comme arachide — met quelqu'un
 *   en danger ;
 * - le faux positif — « lait de coco » pris pour du lait, « farine de riz » pour
 *   du gluten — rend l'application inutilisable et pousse à désactiver le contrôle,
 *   ce qui revient au premier cas.
 *
 * Chaque contre-exemple ci-dessous est couvert par un test (leçon n° 2).
 */
enum Allergen: string
{
    case Gluten = 'gluten';
    case Crustacean = 'crustaces';
    case Egg = 'oeufs';
    case Fish = 'poisson';
    case Peanut = 'arachide';
    case Soy = 'soja';
    case Milk = 'lait';
    case TreeNut = 'fruits-a-coque';
    case Celery = 'celeri';
    case Mustard = 'moutarde';
    case Sesame = 'sesame';
    case Sulphite = 'sulfites';
    case Lupin = 'lupin';
    case Mollusc = 'mollusques';
    case Pork = 'porc';
    case Meat = 'viande';
    case Alcohol = 'alcool';

    public function label(): string
    {
        return match ($this) {
            self::Gluten => 'Gluten',
            self::Crustacean => 'Crustacés',
            self::Egg => 'Œufs',
            self::Fish => 'Poisson',
            self::Peanut => 'Arachides',
            self::Soy => 'Soja',
            self::Milk => 'Lait et produits laitiers',
            self::TreeNut => 'Fruits à coque',
            self::Celery => 'Céleri',
            self::Mustard => 'Moutarde',
            self::Sesame => 'Sésame',
            self::Sulphite => 'Sulfites',
            self::Lupin => 'Lupin',
            self::Mollusc => 'Mollusques',
            self::Pork => 'Porc',
            self::Meat => 'Viande (végétarien)',
            self::Alcohol => 'Alcool',
        };
    }

    /**
     * Ce que l'utilisateur peut écrire pour désigner cet allergène.
     *
     * Saisir « cacahuètes » doit activer toute la famille arachide, pas seulement
     * le mot tapé.
     *
     * @return list<string> termes normalisés
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Gluten => ['gluten', 'ble', 'froment'],
            self::Crustacean => ['crustace', 'crustaces', 'fruits de mer'],
            self::Egg => ['oeuf', 'oeufs'],
            self::Fish => ['poisson', 'poissons'],
            self::Peanut => ['arachide', 'arachides', 'cacahuete', 'cacahuetes', 'cacahouete'],
            self::Soy => ['soja', 'soya'],
            self::Milk => ['lait', 'lactose', 'laitier', 'laitiers', 'laitage', 'produits laitiers'],
            self::TreeNut => ['fruits a coque', 'fruit a coque', 'noix', 'oleagineux'],
            self::Celery => ['celeri'],
            self::Mustard => ['moutarde'],
            self::Sesame => ['sesame'],
            self::Sulphite => ['sulfite', 'sulfites', 'anhydride sulfureux'],
            self::Lupin => ['lupin'],
            self::Mollusc => ['mollusque', 'mollusques'],
            self::Pork => ['porc', 'cochon'],
            self::Meat => ['viande', 'vegetarien', 'vegetarienne'],
            self::Alcohol => ['alcool'],
        };
    }

    /**
     * Termes cherchés dans les recettes, tolérants au pluriel.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        return match ($this) {
            self::Gluten => [
                'gluten', 'ble', 'froment', 'seigle', 'orge', 'epeautre', 'kamut',
                'farine', 'semoule', 'boulgour', 'couscous', 'pain', 'chapelure',
                'panure', 'biscotte', 'brioche', 'croissant', 'malt', 'seitan',
                'vermicelle', 'ravioli', 'lasagne', 'tagliatelle', 'spaghetti', 'macaroni',
            ],
            self::Crustacean => [
                'crustace', 'crevette', 'gamba', 'langoustine', 'langouste', 'homard',
                'crabe', 'tourteau', 'ecrevisse', 'araignee de mer',
            ],
            self::Egg => ['oeuf', 'meringue', 'mayonnaise', 'omelette', 'blanc en neige', 'jaune d oeuf'],
            self::Fish => [
                'poisson', 'saumon', 'thon', 'cabillaud', 'colin', 'merlu', 'lieu',
                'sardine', 'anchois', 'truite', 'maquereau', 'hareng', 'dorade',
                'sole', 'bar', 'surimi', 'nuoc mam', 'anguille', 'espadon', 'raie',
            ],
            self::Peanut => [
                'arachide', 'cacahuete', 'cacahouete', 'satay', 'sate',
            ],
            self::Soy => ['soja', 'soya', 'tofu', 'tempeh', 'miso', 'edamame', 'tamari'],
            self::Milk => [
                'lait', 'beurre', 'creme', 'fromage', 'yaourt', 'lactose', 'caseine',
                'petit lait', 'lactoserum', 'ghee', 'chantilly', 'bechamel',
                'mozzarella', 'parmesan', 'gruyere', 'emmental', 'comte', 'ricotta',
                'mascarpone', 'feta', 'raclette', 'roquefort', 'camembert', 'cheddar',
            ],
            self::TreeNut => [
                'noix', 'noisette', 'amande', 'pistache', 'cajou', 'pecan',
                'macadamia', 'praline', 'nougat', 'frangipane', 'noix du bresil',
            ],
            self::Celery => ['celeri'],
            self::Mustard => ['moutarde'],
            self::Sesame => ['sesame', 'tahin', 'tahini', 'gomasio'],
            self::Sulphite => ['sulfite', 'anhydride sulfureux', 'e220', 'e221', 'e222', 'e223', 'e224'],
            self::Lupin => ['lupin'],
            self::Mollusc => [
                'huitre', 'calamar', 'calmar', 'encornet', 'poulpe', 'seiche',
                'escargot', 'saint jacques', 'bulot', 'palourde', 'coque', 'bigorneau',
            ],
            self::Pork => [
                'porc', 'jambon', 'lardon', 'bacon', 'saucisson', 'chorizo', 'boudin',
                'andouille', 'rillette', 'saindoux', 'couenne', 'pancetta', 'coppa',
                'petit sale', 'poitrine fumee',
            ],
            self::Meat => [
                'viande', 'boeuf', 'veau', 'agneau', 'mouton', 'porc', 'poulet',
                'dinde', 'canard', 'lapin', 'gibier', 'steak', 'escalope', 'gelatine',
                'lardon', 'jambon', 'saucisse', 'merguez', 'bacon', 'volaille',
                'bouillon de volaille', 'bouillon de boeuf', 'chair a saucisse',
            ],
            self::Alcohol => [
                'vin', 'biere', 'rhum', 'whisky', 'vodka', 'cognac', 'liqueur',
                'kirsch', 'calvados', 'armagnac', 'porto', 'champagne', 'cidre',
            ],
        };
    }

    /**
     * Termes cherchés au mot exact, sans tolérance de pluriel.
     *
     * « moule » est le piège du genre : au singulier c'est un ustensile de
     * pâtisserie, au pluriel un mollusque. Tolérer le pluriel sur « moule »
     * écarterait la moitié des recettes de gâteau.
     *
     * @return list<string>
     */
    public function strictTerms(): array
    {
        return match ($this) {
            self::Mollusc => ['moules'],
            self::Gluten => ['pates', 'pate brisee', 'pate feuilletee', 'pate a pizza'],
            default => [],
        };
    }

    /**
     * Expressions qui CONTIENNENT un terme sans être l'allergène.
     *
     * Elles sont retirées du texte avant toute recherche : « lait de coco » disparaît,
     * et le mot « lait » qu'il contenait avec lui.
     *
     * @return list<string>
     */
    public function exceptions(): array
    {
        return match ($this) {
            self::Gluten => [
                'farine de riz', 'farine de mais', 'farine de sarrasin', 'farine de chataigne',
                'farine de pois chiche', 'farine de coco', 'farine d amande', 'farine de manioc',
                'pates de riz', 'pain de mais', 'semoule de mais',
            ],
            self::Milk => [
                // Un « lait » végétal n'est pas du lait, et c'est le contre-exemple
                // le plus courant dans une recette adaptée.
                'lait de coco', 'lait de soja', 'lait d amande', 'lait de riz',
                'lait d avoine', 'lait de noisette', 'lait vegetal', 'lait de cajou',
                'creme de coco', 'creme de soja', 'creme vegetale', 'creme d amande',
                'beurre de cacahuete', 'beurre d arachide', 'beurre de karite',
                'beurre d amande', 'beurre de coco', 'beurre vegetal', 'beurre de noisette',
                'fromage vegetal', 'yaourt de soja', 'yaourt vegetal', 'lait de brebis',
            ],
            self::TreeNut => [
                // La noix de coco n'est pas un fruit à coque au sens réglementaire,
                // et la noix de muscade est une épice.
                'noix de coco', 'noix de muscade', 'lait de coco', 'creme de coco',
                'huile de coco', 'farine de coco',
            ],
            self::Alcohol => [
                'vinaigre de vin', 'vinaigre de vin blanc', 'vinaigre de vin rouge',
                'biere sans alcool', 'vin sans alcool',
            ],
            self::Pork => ['jambon de dinde', 'jambon de volaille', 'bacon de dinde'],
            self::Meat => [
                'steak de soja', 'steak vegetal', 'viande vegetale', 'viande hachee vegetale',
                'bouillon de legumes', 'saucisse vegetale', 'lardon vegetal', 'gelatine vegetale',
            ],
            self::Mollusc => ['moules a gateau', 'moules a tarte'],
            self::Fish => ['sans poisson'],
            default => [],
        };
    }

    /**
     * L'allergène désigné par un texte libre, s'il en désigne un.
     *
     * « allergie aux cacahuètes » comme « cacahuètes » activent la famille arachide.
     */
    public static function fromExclusionText(string $normalized): ?self
    {
        foreach (self::cases() as $allergen) {
            foreach ($allergen->aliases() as $alias) {
                if (1 === preg_match('/\b'.preg_quote($alias, '/').'\b/', $normalized)) {
                    return $allergen;
                }
            }
        }

        return null;
    }
}
