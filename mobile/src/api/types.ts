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

export type StockStatus = 'sealed' | 'opened' | 'running_low' | 'expiring_soon' | 'expired' | 'empty';
export type Feasibility = 'ready' | 'adapted' | 'needs_shopping';

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

export interface StockItem {
  id: string;
  product: Product;
  initialQuantity: number;
  remainingQuantity: number;
  unit: Unit;
  unitLabel: string;
  isDiscreteUnit: boolean;
  displayQuantity: number;
  displayUnit: string;
  numberOfPackages: number;
  storageLocation: StorageLocation;
  storageLocationLabel: string;
  purchaseDate: string | null;
  expirationDate: string | null;
  daysUntilExpiration: number | null;
  openedAt: string | null;
  isOpened: boolean;
  quantityIsEstimated: boolean;
  status: StockStatus;
  note: string | null;
  /** Prénom de la personne qui a enregistré ce produit. */
  addedBy: string | null;
  updatedAt: string;
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
  createdAt: string;
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
  units: Array<ReferenceOption & { family: string; isDiscrete: boolean }>;
  storageLocations: ReferenceOption[];
  fillLevels: Array<ReferenceOption & { ratio: number | null }>;
  productTraits: Array<ReferenceOption & { value: ProductTraitValue }>;
  categories: string[];
  mealTypes: ReferenceOption[];
  equipment: ReferenceOption[];
}

export interface InventoryIngredient {
  key: string;
  label: string;
  quantity: number;
  unit: Unit;
  unitLabel: string;
  expiresSoon: boolean;
  earliestExpiration: string | null;
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

export interface SuggestionResponse {
  recipes: Recipe[];
  inventory: Array<{ key: string; ingredient: string; quantity: number; unit: Unit; expiresSoon: boolean }>;
}

export interface ConfirmationResponse {
  recipeName: string | null;
  lines: Array<{
    key: string;
    label: string;
    requested: number;
    applied: number;
    shortfall: number;
    unit: Unit;
    lots: Array<{ stockItemId: string; quantity: number; unit: Unit; expirationDate: string | null }>;
  }>;
}
