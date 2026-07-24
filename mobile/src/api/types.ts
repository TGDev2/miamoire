export type Unit = 'g' | 'kg' | 'ml' | 'cl' | 'l' | 'piece' | 'portion';
export type StorageLocation = 'pantry' | 'fridge' | 'freezer';
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

export interface HouseholdMember {
  id: string;
  name: string;
  email: string;
}

export interface CurrentUser {
  id: string;
  email: string;
  name: string;
  dietaryExclusions: string[];
  household: { id: string; name: string; members: HouseholdMember[] };
}

export interface ReferenceOption {
  value: string;
  label: string;
}

export interface Reference {
  units: Array<ReferenceOption & { family: string; isDiscrete: boolean }>;
  storageLocations: ReferenceOption[];
  fillLevels: Array<ReferenceOption & { ratio: number | null }>;
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
