export type Unit = 'g' | 'kg' | 'ml' | 'cl' | 'l' | 'piece' | 'portion';
export type StorageLocation = 'pantry' | 'fridge' | 'freezer';

/**
 * L'état réel d'un produit, au-delà de son nom.
 *
 * Des pommes de terre en conserve sont déjà pelées et cuites : sans cette
 * information, la recette générée commence par « épluchez les pommes de terre ».
 */
export type ProductTraitValue =
  | 'canned'
  | 'frozen'
  | 'dried'
  | 'peeled'
  | 'precooked'
  | 'cooked'
  | 'ready_to_eat'
  | 'sliced'
  | 'sweetened'
  | 'unsweetened';

export type StockStatus =
  | 'sealed'
  | 'opened'
  | 'running_low'
  | 'expiring_soon'
  | 'expired'
  | 'empty'
  | 'archived';
export type Feasibility = 'ready' | 'adapted' | 'needs_shopping';
export type UnitFamily = 'mass' | 'volume' | 'count' | 'serving';

/** D'où vient une information : ce que quelqu'un a choisi prime sur ce qu'une machine a déduit. */
export type DataOrigin = 'detected' | 'catalog' | 'user';

/**
 * La fiche canonique, partagée par tous les foyers.
 *
 * Elle sert de cache Open Food Facts et n'est jamais corrigée depuis un lot :
 * renommer un aliment chez soi ne change plus ce que voient les autres.
 */
export interface Product {
  id: string;
  barcode: string | null;
  name: string;
  displayName: string;
  ingredientKey: string;
  brand: string | null;
  category: string | null;
  traits: ProductTraitValue[];
  packageQuantity: number | null;
  packageUnit: Unit | null;
  imageUrl: string | null;
  source: 'openfoodfacts' | 'manual';
}

/**
 * L'aliment tel qu'il existe DANS CE FOYER.
 *
 * C'est le niveau qui manquait entre la référence et le lot : des tomates
 * fraîches et des tomates en conserve sont deux variantes, avec leurs quantités
 * et leurs états propres. `key` est l'identifiant lisible que le générateur de
 * recettes recopie — `tomate`, `tomate-conserve`.
 */
export interface ProductVariant {
  id: string;
  key: string;
  name: string;
  displayName: string;
  /** « Tomates (en conserve) » — ce que lit le générateur de recettes. */
  culinaryLabel: string;
  ingredientKey: string;
  brand: string | null;
  category: string | null;
  traits: ProductTraitValue[];
  unitFamily: UnitFamily;
  nameOrigin: DataOrigin;
  traitsOrigin: DataOrigin;
  /** Ce qui vient du catalogue partagé, non modifiable localement. */
  productId: string | null;
  barcode: string | null;
  imageUrl: string | null;
  catalogName: string | null;
}

/**
 * Un exemplaire d'un lot — un sachet, une bouteille.
 *
 * Trois sachets de pâtes de 500 g dont 250 g ont servi, ce n'est pas « 1,25 kg
 * de pâtes entamées » : c'est un sachet ouvert à 250 g et deux encore scellés.
 */
export interface StockPackage {
  position: number;
  remaining: number;
  opened: boolean;
}

/** Un autre lot du même aliment : même variante, autre date. */
export interface SiblingLot {
  id: string;
  displayQuantity: number;
  displayUnit: string;
  expirationDate: string | null;
  expirationIsEstimated: boolean;
  daysUntilExpiration: number | null;
  storageLocationLabel: string;
  status: StockStatus;
}

export interface StockItem {
  id: string;
  /** Renvoyée en `expectedRevision` pour une correction absolue (quantité, unité). */
  revision: number;
  variant: ProductVariant;
  initialQuantity: number;
  remainingQuantity: number;
  unit: Unit;
  unitLabel: string;
  isDiscreteUnit: boolean;
  displayQuantity: number;
  displayUnit: string;
  numberOfPackages: number;
  /** Contenance d'un exemplaire, dans l'unité du lot. */
  packageSize: number;
  /** Ce qu'il reste dans l'exemplaire entamé ; `null` si aucun ne l'est. */
  openPackageQuantity: number | null;
  sealedPackageCount: number;
  /** Exemplaire par exemplaire, dans l'ordre où ils seront consommés. */
  packages: StockPackage[];
  storageLocation: StorageLocation;
  storageLocationLabel: string;
  purchaseDate: string | null;
  expirationDate: string | null;
  /** La date a été déduite d'une durée de conservation, pas lue sur un emballage. */
  expirationIsEstimated: boolean;
  daysUntilExpiration: number | null;
  openedAt: string | null;
  isOpened: boolean;
  quantityIsEstimated: boolean;
  isArchived: boolean;
  archivedAt: string | null;
  status: StockStatus;
  note: string | null;
  /** Prénom de la personne qui a enregistré ce produit. */
  addedBy: string | null;
  updatedAt: string;
  /** Renseigné sur la fiche d'un lot uniquement. */
  siblings?: SiblingLot[];
}

export interface StockMovement {
  id: string;
  type: 'addition' | 'consumption' | 'correction' | 'deletion';
  typeLabel: string;
  quantity: number;
  unit: Unit;
  remainingAfter: number;
  reason: string | null;
  author: string | null;
  /** Les mouvements d'une même recette partagent cet identifiant. */
  operationId: string | null;
  createdAt: string;
}

/** Combien de temps un aliment se garde, et pourquoi on le pense. */
export interface ShelfLifeEstimate {
  days: number;
  until: string;
  source: 'food' | 'trait' | 'category' | 'location';
  matched: string | null;
  explanation: string;
}

export interface Member {
  id: string;
  firstName: string;
  householdId: string;
}

/**
 * Un foyer : le périmètre d'isolation du stock. Aucun compte, aucun mot de passe —
 * un code d'accès partagé entre les membres, et rien d'autre.
 *
 * `id` n'est pas un secret : il circule dans toutes les réponses de l'API.
 */
export interface Household {
  id: string;
  name: string;
  dietaryExclusions: string[];
  createdAt: string;
  members: Member[];
}

/**
 * Le foyer accompagné de son code en clair.
 *
 * Renvoyé uniquement à la création et à la régénération : le serveur n'en garde
 * qu'une empreinte et ne pourra plus jamais l'afficher. C'est à l'application de
 * le conserver.
 */
export interface HouseholdWithKey extends Household {
  accessKey: string;
}

export interface ReferenceOption {
  value: string;
  label: string;
}

export interface Reference {
  units: Array<ReferenceOption & { family: UnitFamily; isDiscrete: boolean }>;
  storageLocations: ReferenceOption[];
  fillLevels: Array<ReferenceOption & { ratio: number | null }>;
  productTraits: Array<ReferenceOption & { value: ProductTraitValue }>;
  /**
   * Exclusions proposées à la sélection : choisir « Arachides » couvre aussi
   * « beurre de cacahuète » et « satay », ce qu'un texte libre ne peut pas faire.
   */
  allergens: ReferenceOption[];
  categories: string[];
  mealTypes: ReferenceOption[];
  equipment: ReferenceOption[];
}

export interface InventoryIngredient {
  /** Identifiant lisible de la variante : `tomate`, `tomate-conserve`. */
  key: string;
  variantId: string;
  label: string;
  name: string;
  quantity: number;
  unit: Unit;
  unitLabel: string;
  expiresSoon: boolean;
  /** Au moins un exemplaire est entamé : les recettes le privilégient. */
  alreadyOpened: boolean;
  earliestExpiration: string | null;
  expirationIsEstimated: boolean;
  quantityIsEstimated: boolean;
  category: string | null;
  traits: ProductTraitValue[];
  locations: StorageLocation[];
  lotCount: number;
}

export interface InventorySummary {
  ingredients: InventoryIngredient[];
  stats: { distinctIngredients: number; lots: number; expiringSoon: number; expired: number };
}

export interface RecipeIngredient {
  key?: string;
  label: string;
  quantity: number;
  unit: Unit;
  essential: boolean;
}

export interface ConsumptionLine {
  key: string;
  label: string;
  quantity: number;
  unit: Unit;
}

export interface Recipe {
  recipeName: string;
  feasibility: Feasibility;
  servings: number;
  preparationTime: number;
  summary: string;
  ingredientsFromInventory: RecipeIngredient[];
  missingIngredients: RecipeIngredient[];
  substitutions: Array<{ original: string; replacedBy: string; reason: string }>;
  steps: string[];
  stockConsumptionProposal: ConsumptionLine[];
}

/** Une proposition écartée par le contrôle des exclusions, et la raison exacte. */
export interface RejectedRecipe {
  recipeName: string;
  reason: string;
}

export interface SuggestionResponse {
  recipes: Recipe[];
  /** Recettes supprimées avant affichage : aliment exclu, ou aliment imposé absent. */
  rejected: RejectedRecipe[];
  /** Les exclusions réellement vérifiées sur chaque recette, pas seulement demandées. */
  guaranteedExclusions: string[];
  /** L'aliment que l'utilisateur a imposé, quand il en a choisi un. */
  requiredIngredient: { key: string; label: string } | null;
  inventory: Array<{ key: string; ingredient: string; quantity: number; unit: Unit; expiresSoon: boolean }>;
}

/**
 * Une recette que le foyer a décidé de garder.
 *
 * Elle vit sur le serveur et non dans la mémoire de l'application : quitter
 * l'écran de suggestion ne la perd plus, et les autres membres du foyer y ont
 * accès depuis leur propre téléphone.
 */
export interface SavedRecipe {
  id: string;
  recipeName: string;
  savedBy: string | null;
  savedAt: string;
  recipe: Recipe;
}

export interface ConfirmationResponse {
  recipeName: string | null;
  /** La clé d'idempotence : rejouée, elle renvoie cette même réponse sans rien déduire. */
  operationKey: string;
  /** Vrai quand le serveur a reconnu une confirmation déjà appliquée. */
  replayed: boolean;
  lines: Array<{
    key: string;
    label: string;
    requested: number;
    applied: number;
    shortfall: number;
    unit: Unit;
    /** La déduction s'appuie sur une quantité approximative : à vérifier après le repas. */
    quantityWasEstimated: boolean;
    lots: Array<{
      stockItemId: string;
      quantity: number;
      unit: Unit;
      expirationDate: string | null;
      expirationIsEstimated: boolean;
    }>;
  }>;
}
