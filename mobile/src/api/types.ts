export type Unit = 'g' | 'kg' | 'ml' | 'cl' | 'l' | 'piece' | 'portion';
export type StorageLocation = 'pantry' | 'fridge' | 'freezer';
/** Ce qu'une remise à zéro vide : tout le stock, ou un seul emplacement. */
export type ResetScope = 'all' | StorageLocation;

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
  | 'sweetened'
  | 'unsweetened';

/**
 * La FORME sous laquelle un aliment se présente — ce qui décide de sa cuisson.
 *
 * Une escalope se saisit trois minutes par face, de l'émincé saute deux minutes
 * à feu vif, un blanc entier en cuit vingt. Sans cet axe, le générateur a écrit
 * une recette d'escalopes sur du blanc de poulet en tranches.
 *
 * `null` veut dire « forme inconnue », jamais « entière ».
 */
export type ProductCutValue =
  | 'whole'
  | 'escalope'
  | 'steak'
  | 'bone_in'
  | 'strips'
  | 'slices'
  | 'cubes'
  | 'pieces'
  | 'ground'
  | 'grated'
  | 'puree';

export type StockStatus =
  | 'sealed'
  /** Produit frais : il n'a pas d'emballage, donc il n'est ni fermé ni entamé. */
  | 'fresh'
  | 'opened'
  /** Entamé, refermé sans être fini : reste prioritaire, juste rangé différemment. */
  | 'resealed'
  | 'running_low'
  | 'expiring_soon'
  | 'expired'
  /** Frozen for longer than advised: still safe, best eaten soon — never "expired". */
  | 'frozen_past_best'
  | 'empty'
  | 'archived';
export type Feasibility = 'ready' | 'adapted' | 'needs_shopping';
export type UnitFamily = 'mass' | 'volume' | 'count' | 'serving';

/** Ce que signifie une exclusion : une allergie stricte ne s'assouplit jamais faute de donnée. */
export type ExclusionSeverity = 'allergy' | 'diet' | 'preference';

export interface DietaryExclusion {
  label: string;
  severity: ExclusionSeverity;
}

/**
 * L'état réel d'une recette, recalculé au moment où on la regarde.
 *
 * `incompatible` et `safety_unknown` s'ajoutent aux trois statuts que l'IA
 * s'attribue elle-même (`Feasibility`) : ils viennent d'une revérification
 * serveur, pas d'une auto-évaluation, et priment sur elle à l'affichage.
 */
export type RecipePlanStatus =
  | 'ready'
  | 'needs_shopping'
  | 'needs_reassociation'
  | 'safety_unknown'
  | 'incompatible';

export interface RecipePlanLine {
  key: string;
  label: string;
  status: RecipePlanStatus;
  detail: string | null;
}

export interface RecipePlan {
  status: RecipePlanStatus;
  reason: string | null;
  lines: RecipePlanLine[];
  /** Les exclusions strictes réellement confrontées à la composition des produits. */
  checkedExclusions: string[];
  evaluatedAt: string;
}

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
  /** La forme physique de l'aliment. `null` : inconnue, jamais « entière ». */
  cut: ProductCutValue | null;
  cutLabel: string | null;
  cutOrigin: DataOrigin;
  /**
   * Les seules découpes qu'on propose pour CET aliment : quatre puces pour un
   * blanc de poulet, aucune pour une farine. La question n'est posée que
   * lorsqu'elle change une recette.
   */
  plausibleCuts: ProductCutValue[];
  unitFamily: UnitFamily;
  nameOrigin: DataOrigin;
  traitsOrigin: DataOrigin;
  /** Frais ou conditionné : décide du formulaire de saisie et du vocabulaire de la fiche. */
  nature: FoodNature;
  natureLabel: string;
  natureOrigin: DataOrigin;
  /** Ce qui vient du catalogue partagé, non modifiable localement. */
  productId: string | null;
  barcode: string | null;
  imageUrl: string | null;
  catalogName: string | null;
  /** Habitudes d'achat : `null` tant qu'aucun lot n'a jamais été créé pour cette variante. */
  usualPackageSize: number | null;
  usualPackageUnit: Unit | null;
  usualStorageLocation: StorageLocation | null;
  usualStorageLocationLabel: string | null;
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

/** D'où vient `effectiveUseBy` : ce qui a gagné entre la date et la durée après ouverture. */
/**
 * `frozen`: in the freezer, the date is a quality limit (the printed date is
 * kept, but no longer drives the urgency). `thawed`: out of the freezer, due
 * within 24 hours.
 */
export type EffectiveUseByBasis = 'printed' | 'estimated' | 'after_opening' | 'unknown' | 'frozen' | 'thawed';

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
  /** Entamé mais refermé sans être fini : reste prioritaire, juste rangé différemment. */
  isPackageResealed: boolean;
  quantityIsEstimated: boolean;
  isArchived: boolean;
  archivedAt: string | null;
  status: StockStatus;
  /**
   * La plus proche de la date imprimée/estimée et de la durée de conservation
   * après ouverture — ce qui pilote réellement le stock, Cuisiner et les
   * notifications. `null` seulement quand aucune date n'est connue du tout.
   */
  effectiveUseBy: string | null;
  effectiveUseByBasis: EffectiveUseByBasis;
  /** `null` : lot non entamé, la question ne se pose pas. `false` : entamé mais durée inconnue. */
  afterOpeningDurationKnown: boolean | null;
  daysUntilEffectiveUseBy: number | null;
  /** When the lot first went into the freezer; `null` if unknown or never. */
  frozenAt: string | null;
  /** When the lot last came out of the freezer. */
  thawedAt: string | null;
  note: string | null;
  /** Prénom de la personne qui a enregistré ce produit. */
  addedBy: string | null;
  updatedAt: string;
  /** Renseigné sur la fiche d'un lot uniquement. */
  siblings?: SiblingLot[];
}

/** Pourquoi un lot est proposé à « Vérifier mon stock ». */
export type ReviewReasonCode =
  | 'opened'
  | 'quantity_estimated'
  | 'expired'
  | 'expiring_soon'
  | 'stale';

export interface ReviewReasonEntry {
  code: ReviewReasonCode;
  label: string;
}

/** Un lot de la file de vérification, et pourquoi il y figure. */
export interface ReviewCandidate {
  stockItem: StockItem;
  reasons: ReviewReasonEntry[];
}

/** Pourquoi un lot est proposé par « Aujourd'hui ». Distinct de `ReviewReasonCode`. */
export type UrgencyReasonCode = 'expired' | 'expiring_soon' | 'opened';

export interface UrgencyReasonEntry {
  code: UrgencyReasonCode;
  label: string;
}

/** Un lot à traiter maintenant, et pourquoi il y figure. */
export interface SaveTodayCandidate {
  stockItem: StockItem;
  reasons: UrgencyReasonEntry[];
}

/** Pourquoi un produit est proposé par « J'ai utilisé quelque chose ». Distinct de `ReviewReasonCode`. */
export type QuickConsumeReasonCode =
  | 'opened'
  | 'recently_consumed'
  | 'recently_used_in_recipe'
  | 'recently_viewed';

export interface QuickConsumeReasonEntry {
  code: QuickConsumeReasonCode;
  label: string;
}

/** Un produit plausible à consommer là maintenant, et pourquoi il y figure. */
export interface QuickConsumeCandidate {
  stockItem: StockItem;
  reasons: QuickConsumeReasonEntry[];
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

/**
 * Un aliment est-il protégé par son emballage, ou se dégrade-t-il déjà ?
 *
 * Tout le formulaire d'ajout en découle. Une courgette n'a ni « exemplaires »,
 * ni « quantité par exemplaire », ni date imprimée : lui poser ces questions
 * revenait à lui demander d'inventer un poids qu'elle ne connaît pas.
 */
export type FoodNature = 'fresh' | 'packaged';

/**
 * Ce que le serveur sait d'un aliment avant même qu'on l'ajoute — de quel genre
 * il est, dans quelle unité il se saisit, et combien de temps il se garde.
 *
 * `naturalUnit` n'est renseignée que pour un aliment frais reconnu : c'est la
 * seule situation où l'application a mieux à proposer que « grammes ».
 */
export interface FoodProfile {
  nature: FoodNature;
  natureLabel: string;
  naturalUnit: Unit | null;
  naturalUnitLabel: string | null;
  /** Poids indicatif d'une pièce, transmis au générateur de recettes. */
  approxPieceWeight: number | null;
  /** Vide quand la forme de cet aliment ne change rien : l'écran n'affiche alors aucune puce. */
  plausibleCuts: ProductCutValue[];
  /** Celle que le libellé nomme déjà, s'il en nomme une. */
  detectedCut: ProductCutValue | null;
  /**
   * The cut that will be saved if nobody picks one: named by the label,
   * remembered from this household, or "whole" for fresh produce. The chip
   * arrives selected.
   */
  suggestedCut: ProductCutValue | null;
  suggestedCutSource: CutSuggestionSource | null;
  /** The cut decides the cooking (meat, fish): only then is an unknown cut asked. */
  cutMatters: boolean;
  shelfLife: ShelfLifeEstimate;
}

export type CutSuggestionSource = 'label' | 'household' | 'produce';

/** A food in stock whose cut is unknown although it would change a recipe. */
export interface CutQueueItem {
  variant: ProductVariant;
  /** One lot of the food: correcting its cut answers for the whole food. */
  stockItemId: string;
  lotCount: number;
  suggestedCut: ProductCutValue | null;
  suggestedCutSource: CutSuggestionSource | null;
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
  dietaryExclusions: DietaryExclusion[];
  /** Catégories définitivement écartées de « Vérifier mon stock ». */
  mutedReviewCategories: string[];
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
  /** La liste complète, pour l'écran de correction. */
  productCuts: Array<ReferenceOption & { value: ProductCutValue }>;
  /**
   * Exclusions proposées à la sélection : choisir « Arachides » couvre aussi
   * « beurre de cacahuète » et « satay », ce qu'un texte libre ne peut pas faire.
   */
  allergens: Array<ReferenceOption & { defaultSeverity: ExclusionSeverity }>;
  categories: string[];
  mealTypes: ReferenceOption[];
  mealTemperatures: ReferenceOption[];
  /** Féculent, légume, protéine : les composantes qu'on peut exiger d'un plat. */
  foodGroups: ReferenceOption[];
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
  /** Dérivé de `deadlines` : vrai si au moins un groupe l'est. */
  expiresSoon: boolean;
  /**
   * Produit frais : il se dégrade dès l'achat, là où une conserve attend deux
   * ans. Les recettes le privilégient juste après ce qui périme sous trois jours.
   */
  perishable: boolean;
  /** Dérivé de `deadlines` : au moins un exemplaire est entamé, les recettes le privilégient. */
  alreadyOpened: boolean;
  earliestExpiration: string | null;
  expirationIsEstimated: boolean;
  quantityIsEstimated: boolean;
  category: string | null;
  traits: ProductTraitValue[];
  /** La forme de l'aliment, quand elle est connue — elle décide de sa cuisson. */
  cut: ProductCutValue | null;
  locations: StorageLocation[];
  lotCount: number;
  /**
   * Le conditionnement, quand « un exemplaire » veut dire quelque chose — deux
   * boîtes de 400 g. `null` sinon : aliment déjà compté à la pièce, ou lots aux
   * contenances différentes. L'écran « Cuisiner » n'offre alors pas le choix
   * entre écouler une quantité et écouler des exemplaires.
   */
  packaging: InventoryPackaging | null;
  /**
   * La même quantité ventilée par échéance effective — « 250 g entamés à
   * utiliser demain, 1 kg scellé plus tard » plutôt qu'un seul total marqué
   * globalement urgent.
   */
  deadlines: InventoryDeadlineBucket[];
}

export interface InventoryPackaging {
  /** Exemplaires restants, celui qui est entamé compris. */
  count: number;
  /** Contenance d'un exemplaire, dans `unit`. */
  size: number;
  unit: Unit;
  unitLabel: string;
}

/**
 * Un aliment imposé à la génération, et — facultativement — ce qu'on veut en
 * écouler.
 *
 * Deux écritures possibles et au plus une : une quantité dans une unité
 * (« 400 g »), ou un nombre d'exemplaires (« 1 boîte »). Aucune n'est
 * obligatoire : imposer un aliment sans dire combien reste le cas courant.
 */
export interface Priority {
  key: string;
  quantity?: number;
  unit?: Unit;
  packages?: number;
}

/** Une demande de recettes : la même pour une génération et pour son export. */
export interface RecipeRequestBody {
  people: number;
  mealType?: string | null;
  mealTemperature?: string | null;
  maxMinutes?: number | null;
  equipment: string[];
  exclusions: string[];
  allowExtraIngredients: boolean;
  priorities?: Priority[];
  /** Chaque recette doit contenir un aliment de chacun de ces groupes. */
  foodGroups?: string[];
}

export interface InventoryDeadlineBucket {
  quantity: number;
  useBy: string | null;
  useByIsEstimated: boolean;
  opened: boolean;
  expiresSoon: boolean;
  /** In the freezer: `useBy` is a quality limit, never urgent. */
  frozen: boolean;
  pastBest: boolean;
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
  /**
   * La forme que la recette a supposée, quand l'inventaire ne la donnait pas.
   *
   * `'none'` : rien n'a été supposé, la découpe était connue. Absent sur les
   * recettes enregistrées avant ce champ, comme `utensils`.
   */
  assumedCut?: ProductCutValue | 'none';
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
  /** Vrai seulement quand un accompagnement a été ajouté parce que le plat seul ne suffisait pas. */
  includesSideDish: boolean;
  /** Absent sur les recettes enregistrées avant l'ajout de ce champ. */
  utensils?: string[];
  ingredientsFromInventory: RecipeIngredient[];
  missingIngredients: RecipeIngredient[];
  substitutions: Array<{ original: string; replacedBy: string; reason: string }>;
  steps: string[];
  stockConsumptionProposal: ConsumptionLine[];
  /** Recalculé à la génération : source de vérité au-delà de `feasibility`, que l'IA s'attribue elle-même. */
  plan: RecipePlan;
}

/** Une proposition écartée par le contrôle des exclusions, et la raison exacte. */
export interface RejectedRecipe {
  recipeName: string;
  reason: string;
}

/**
 * Un aliment privilégié tel que le serveur l'a réellement retenu.
 *
 * La cible peut différer de ce qui a été demandé — elle est plafonnée au stock
 * réel, et abandonnée quand elle n'était pas exploitable. `targetLabel` est la
 * phrase à afficher telle quelle : le serveur écrit la même dans le prompt et
 * dans ses motifs de rejet, pour que trois écritures d'une seule quantité ne
 * se contredisent jamais.
 */
export interface PriorityOutcome {
  key: string;
  label: string;
  targetQuantity: number | null;
  targetUnit: Unit | null;
  targetLabel: string | null;
}

export interface SuggestionResponse {
  recipes: Recipe[];
  /** Recettes supprimées avant affichage : aliment exclu, ou aliment imposé absent. */
  rejected: RejectedRecipe[];
  /** Les exclusions réellement vérifiées sur chaque recette, pas seulement demandées. */
  guaranteedExclusions: string[];
  /** L'aliment que l'utilisateur a imposé, quand il en a choisi un. */
  requiredIngredient: PriorityOutcome | null;
  /** Aliments souhaités mais non imposés : une recette qui les ignore n'est pas écartée. */
  desiredIngredients: PriorityOutcome[];
  inventory: Array<{
    key: string;
    ingredient: string;
    quantity: number;
    unit: Unit;
    deadlines: Array<{ quantity: number; useBy: string | null; opened?: true; expiresSoon?: true; frozen?: true; pastBest?: true }>;
  }>;
}

/**
 * A recipe read from another assistant's answer, before the user keeps it.
 *
 * `notices` names what will not be deducted from the stock and why: a line
 * the server could not tie to a food of the household, or whose unit cannot
 * apply to it. They are said before saving, never discovered at cooking time.
 */
export interface ImportedRecipe {
  recipe: Recipe;
  notices: string[];
}

export interface RecipeImportResponse {
  recipes: ImportedRecipe[];
  /** Refused (an excluded food) or unreadable (a cut block), with the reason. */
  rejected: RejectedRecipe[];
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
  schemaVersion: number;
  recipe: Recipe;
}

/**
 * Le créneau d'un repas planifié — distinct de `mealType` (Cuisiner), qui
 * catégorise une recette plutôt qu'il n'ordonne une journée du plan.
 */
export type MealSlot = 'breakfast' | 'lunch' | 'dinner' | 'snack';

export type MealPlanLineStatus = 'covered' | 'short' | 'needs_reassociation';

/** Un autre repas planifié qui réclame le même ingrédient — informatif, jamais un signal de pénurie. */
export interface MealPlanCrossReference {
  entryId: string;
  displayName: string;
  date: string;
  slotLabel: string;
}

export interface MealPlanLine {
  key: string;
  label: string;
  requestedQuantity: number;
  requestedUnit: Unit;
  status: MealPlanLineStatus;
  statusLabel: string;
  /** Dans `requestedUnit`, une fois les repas plus proches déjà servis. */
  shortfall: number;
  detail: string | null;
  alsoClaimedBy: MealPlanCrossReference[];
}

export type MealPlanEntryKind = 'saved_recipe' | 'snapshot' | 'freeform';

/** Un repas à venir, avec son plan de préparation et ses lignes de réservation recalculés à l'instant. */
export interface MealPlanEntry {
  id: string;
  date: string;
  mealSlot: MealSlot;
  mealSlotLabel: string;
  kind: MealPlanEntryKind;
  displayName: string;
  savedRecipeId: string | null;
  label: string | null;
  /** `null` pour un repas libre. */
  recipe: Recipe | null;
  addedBy: string | null;
  createdAt: string;
  /** `null` pour un repas libre — rien à préparer, donc rien à vérifier. */
  plan: RecipePlan | null;
  lines: MealPlanLine[];
}

/** Un manque déjà agrégé sur l'ensemble du plan — jamais un manque par repas. */
export interface MealPlanShortfallLine {
  label: string;
  quantity: number;
  unit: Unit;
}

export interface MealPlanOverview {
  entries: MealPlanEntry[];
  shortfallSummary: MealPlanShortfallLine[];
  unclaimedUrgentItems: SaveTodayCandidate[];
}

/**
 * Une ligne de la liste de courses du foyer.
 *
 * `quantity` est facultative — un simple rappel (« acheter du pain ») reste
 * valide sans quantité précise — mais l'unité, elle, ne l'est jamais : c'est
 * elle qui permet de reconnaître le même produit au scan (voir
 * `shoppingListItem` sur la réponse de `lookupBarcode`).
 */
export interface ShoppingListItem {
  id: string;
  label: string;
  quantity: number | null;
  unit: Unit;
  unitLabel: string;
  checked: boolean;
  checkedAt: string | null;
  variantId: string | null;
  addedBy: string | null;
  createdAt: string;
}

/** Pourquoi le conseiller avant les courses retient une variante — un seul motif à la fois. */
export type ShoppingAdviceKind =
  | 'use_soon_dont_add'
  | 'sufficient_for_planned_meals'
  | 'dont_rebuy'
  | 'running_out';

/** `certaine` : constatée, pas prédite. `elevee`/`moyenne` : dérivée d'un rythme de consommation estimé. */
export type ShoppingAdviceConfidence = 'certaine' | 'elevee' | 'moyenne';

/**
 * Une suggestion avant les courses, avec son raisonnement et sa confiance déjà
 * composés côté serveur — l'utilisateur reste seul décisionnaire, jamais une
 * décision automatique.
 */
export interface ShoppingAdvisorCandidate {
  variant: ProductVariant;
  kind: ShoppingAdviceKind;
  kindLabel: string;
  confidence: ShoppingAdviceConfidence;
  confidenceLabel: string;
  headline: string;
  reasoning: string;
  /** Non `null` si une ligne de la liste de courses existe déjà pour cette variante. */
  existingShoppingListItemId: string | null;
}

/** Ce qu'il y a déjà chez soi pour une variante scannée — le mode « vérifier avant d'acheter ». */
export interface CurrentStockSummary {
  quantity: number;
  unit: Unit;
  unitLabel: string;
  nextUseBy: string | null;
  nextUseByIsEstimated: boolean;
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

/**
 * The grocery return: the basket resolved by the server in one call, then
 * written in one atomic operation (see `api.resolveGroceryReturn` and
 * `api.confirmGroceryReturn`).
 */
export type GroceryReturnStatus = 'ready' | 'needs_decision' | 'unknown';

/**
 * The one question a resolved line may ask. `none`: nothing to decide.
 * `unknown_food`: no name, the user must type one. `unit_mismatch`: the
 * household counts this food in another unit family. `fridge_or_freezer`: a
 * protein with no habit. `short_date`: a chilled packaged food whose estimate
 * is short — the printed date decides the FEFO order.
 */
export type GroceryReturnDecision = 'none' | 'unknown_food' | 'unit_mismatch' | 'fridge_or_freezer' | 'short_date';

/** Where each filled field comes from, shown as a caption, never as a colour. */
export interface GroceryReturnProvenance {
  storage: 'given' | 'habit' | 'default';
  package: 'given' | 'habit' | 'catalog' | 'list' | 'none';
  date: 'printed' | 'estimated' | 'none';
}

/**
 * One basket line as sent to `resolve`. Every field the user has not set by
 * hand is `null`: the server fills only nulls and never overrides a given
 * value. `name` may be "" (unknown). `estimateExpiration`: `null` lets the
 * server estimate, `false` means the user cleared the date, `true` asks for
 * an estimate.
 */
export interface GroceryReturnLineInput {
  lineKey: string;
  barcode: string | null;
  productId: string | null;
  shoppingListItemId: string | null;
  name: string;
  brand: string | null;
  unit: Unit | null;
  quantityPerPackage: number | null;
  numberOfPackages: number;
  storageLocation: StorageLocation | null;
  traits: ProductTraitValue[] | null;
  cut: ProductCutValue | null;
  nature: FoodNature | null;
  expirationDate: string | null;
  estimateExpiration: boolean | null;
  /**
   * The household food the line already names — a receipt label the household
   * taught. The server takes it as the identity before the name.
   */
  variantId?: string | null;
}

export interface GroceryReturnResolvedLine {
  lineKey: string;
  status: GroceryReturnStatus;
  decision: GroceryReturnDecision;
  /** The French question to display, e.g. « Frigo ou congélateur ? ». */
  decisionLabel: string | null;
  name: string;
  brand: string | null;
  productId: string | null;
  barcode: string | null;
  /** The household food when known. */
  variant: ProductVariant | null;
  /** The matched shopping list line. */
  shoppingListItem: ShoppingListItem | null;
  unit: Unit;
  unitFamily: UnitFamily;
  /** `null` when nothing is known: the user must type it. */
  quantityPerPackage: number | null;
  numberOfPackages: number;
  storageLocation: StorageLocation;
  nature: FoodNature;
  natureLabel: string;
  traits: ProductTraitValue[];
  cut: ProductCutValue | null;
  plausibleCuts: ProductCutValue[];
  cutMatters: boolean;
  /** The estimate when `expirationIsEstimated`, else the printed date the user gave; `null` when cleared. */
  expirationDate: string | null;
  expirationIsEstimated: boolean;
  expirationExplanation: string | null;
  provenance: GroceryReturnProvenance;
}

export interface GroceryReturnConfirmBatchInput {
  numberOfPackages: number;
  expirationDate: string | null;
  estimateExpiration: boolean;
}

/**
 * One line as written by `confirm`. `expirationDate` only for a printed date
 * (then `estimateExpiration: false`); `estimateExpiration: true` and no date
 * for an estimated one; both off when the user cleared the date.
 */
export interface GroceryReturnConfirmLineInput {
  lineKey: string;
  barcode: string | null;
  productId: string | null;
  shoppingListItemId: string | null;
  name: string;
  brand: string | null;
  unit: Unit;
  quantityPerPackage: number;
  numberOfPackages: number;
  storageLocation: StorageLocation;
  traits: ProductTraitValue[] | null;
  nature: FoodNature | null;
  cut: ProductCutValue | null;
  note: string | null;
  expirationDate: string | null;
  estimateExpiration: boolean;
  /** One entry per expiration date, or `null` for a single lot. */
  batches: GroceryReturnConfirmBatchInput[] | null;
  /**
   * The printed line this food came from. The server remembers that this
   * label at this shop means the confirmed food: the next receipt resolves it
   * alone.
   */
  receipt?: GroceryReturnReceiptLabel | null;
}

/** A printed label at a shop, as `confirm` learns it — as a food, or as not food. */
export interface GroceryReturnReceiptLabel {
  store: string | null;
  rawLabel: string;
}

export interface GroceryReturnConfirmResult {
  items: StockItem[];
  removedShoppingListItemIds: string[];
  /** The server recognised an operation already applied: same payload, nothing written twice. */
  replayed?: boolean;
  /** Receipt labels remembered by this confirmation (foods and lines set aside). */
  learntLabels?: number;
}

/** The printed line a basket line came from, kept on the line until it is written. */
export interface ReceiptLineOrigin {
  store: string | null;
  rawLabel: string;
  lineTotal: number | null;
}

/** A receipt line that is not food — said by the model, or by a label the household taught (`learned`). */
export interface ReceiptIgnoredLine {
  store: string | null;
  rawLabel: string;
  /** The label in plain words, when the model could expand it; may be empty. */
  expandedName: string;
  learned: boolean;
}

/** One food of the receipt: resolved like any basket line, plus the printed line it came from. */
export type ReceiptReadLine = GroceryReturnResolvedLine & { receipt: ReceiptLineOrigin };

/**
 * What one receipt yielded (see `api.readReceipt`): the foods, already
 * resolved, and the lines set aside. `store`, `receiptDate` and `total` are
 * `null` when the photo did not show them.
 */
export interface ReceiptReading {
  store: string | null;
  receiptDate: string | null;
  total: number | null;
  lines: ReceiptReadLine[];
  ignored: ReceiptIgnoredLine[];
}

/** One refused line of a `422`: nothing was written, the message goes next to the line. */
export interface GroceryReturnLineError {
  lineKey: string;
  message: string;
}
