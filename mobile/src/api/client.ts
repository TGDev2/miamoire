import { getApiBaseUrl } from './config';
import type {
  ConfirmationResponse,
  ConsumptionLine,
  Household,
  HouseholdWithKey,
  InventorySummary,
  Member,
  Product,
  ProductTraitValue,
  Recipe,
  Reference,
  SavedRecipe,
  ShelfLifeEstimate,
  StockItem,
  StockMovement,
  SuggestionResponse,
  Unit,
} from './types';

/**
 * Une clé stable pour un même geste, à conserver jusqu'à la réponse.
 *
 * Rejouée après une coupure réseau — la même clé, pas une nouvelle — le
 * serveur reconnaît le geste au lieu de l'appliquer une seconde fois.
 */
export function generateOperationKey(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/**
 * Identité courante : quel foyer, avec quelle clé, et qui agit.
 *
 * Il n'y a ni e-mail ni mot de passe. Le foyer est une porte dont chaque membre a
 * la clé : le code authentifie et isole le stock, le prénom signe les mouvements.
 * L'identifiant du foyer n'est PAS un secret — il circule dans les réponses de
 * l'API. Seul le code en est un.
 */
let currentHouseholdId: string | null = null;
let currentMemberId: string | null = null;
let currentHouseholdKey: string | null = null;

export function setIdentity(
  householdId: string | null,
  memberId: string | null,
  householdKey: string | null = null,
): void {
  currentHouseholdId = householdId;
  currentMemberId = memberId;
  currentHouseholdKey = householdKey;
}

/**
 * Que faire quand le serveur refuse le code — régénéré depuis un autre téléphone,
 * ou foyer recréé. La session s'en charge : elle oublie le code et l'application
 * revient à l'écran de mise en route, plutôt que d'afficher « 401 » à chaque geste.
 */
let onUnauthorized: (() => void) | null = null;

export function setUnauthorizedHandler(handler: (() => void) | null): void {
  onUnauthorized = handler;
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  /** Les trois appels qui précèdent l'accès à un foyer : santé, création, adhésion. */
  anonymous?: boolean;
};

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, anonymous = false } = options;
  const baseUrl = getApiBaseUrl();

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (!anonymous && currentHouseholdId) headers['X-Household'] = currentHouseholdId;
  if (!anonymous && currentMemberId) headers['X-Member'] = currentMemberId;
  if (!anonymous && currentHouseholdKey) headers['X-Household-Key'] = currentHouseholdKey;

  let response: Response;
  try {
    response = await fetch(`${baseUrl}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(
      `Serveur injoignable (${baseUrl}). Vérifiez que l'API tourne et corrigez au besoin son adresse.`,
      0,
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();

  // Toutes les réponses de l'API sont du JSON — sauf celles qui ne viennent pas
  // d'elle. Une page d'erreur HTML de répartiteur, un portail Wi-Fi captif, une
  // passerelle en 502 : `JSON.parse` lançait alors un `SyntaxError` brut, que
  // les écrans affichent tel quel (« Unexpected token < in JSON »). C'est une
  // panne de transport, et elle doit se présenter comme telle.
  let payload: unknown = null;
  if (text) {
    try {
      payload = JSON.parse(text) as unknown;
    } catch {
      throw new ApiError(
        `Réponse inattendue du serveur (${response.status}). Vérifiez l'adresse de l'API.`,
        response.status,
      );
    }
  }

  if (!response.ok) {
    const message =
      payload && typeof payload === 'object' && 'error' in payload
        ? String((payload as { error: unknown }).error)
        : `Erreur ${response.status}`;

    // Le code n'ouvre plus ce foyer : inutile de le réessayer sur chaque écran.
    if (response.status === 401 && !anonymous) onUnauthorized?.();

    throw new ApiError(message, response.status);
  }

  return payload as T;
}

/**
 * Une date de péremption, et ce qu'on ajoute à cette date.
 *
 * Trois yaourts pour le 27 et deux pour le 3 août, c'est un seul passage en
 * caisse mais deux lots : les fondre en un seul ferait mentir la date la plus
 * proche, donc les alertes et la consommation FEFO.
 */
export interface StockBatchInput {
  numberOfPackages?: number;
  quantityPerPackage?: number;
  expirationDate?: string | null;
  /** Aucune date imprimée : le serveur la déduit d'une durée de conservation. */
  estimateExpiration?: boolean;
  storageLocation?: string;
  note?: string;
}

export interface CreateStockItemInput {
  productId?: string;
  barcode?: string;
  name?: string;
  brand?: string;
  category?: string;
  numberOfPackages: number;
  quantityPerPackage: number;
  unit: Unit;
  storageLocation: string;
  expirationDate?: string | null;
  estimateExpiration?: boolean;
  note?: string;
  quantityIsEstimated?: boolean;
  /** Omis, l'état est déduit du libellé côté serveur. */
  traits?: ProductTraitValue[];
  /** Une entrée par date de péremption. Omis, un seul lot est créé. */
  batches?: StockBatchInput[];
}

export interface StockFilters {
  location?: string;
  category?: string;
  search?: string;
  expiringWithinDays?: number;
  /** Les lots archivés ne remontent que si on les demande. */
  archived?: boolean;
}

export const api = {
  /** Seule route qui répond sans foyer : elle sert à vérifier que le serveur est joignable. */
  health: () => request<{ status: string; time: string }>('/health', { anonymous: true }),

  /**
   * Crée un foyer et reçoit son code — la seule fois où il circule en clair.
   * Le serveur n'en garde qu'une empreinte et ne pourra jamais le réafficher.
   */
  createHousehold: (name: string, firstName: string) =>
    request<HouseholdWithKey>('/households', {
      method: 'POST',
      body: { name, firstName },
      anonymous: true,
    }),

  /** Rejoindre un foyer par son code : il n'existe plus de liste des foyers. */
  joinHousehold: (code: string) =>
    request<Household>('/households/join', {
      method: 'POST',
      body: { code },
      anonymous: true,
    }),

  /** Délivre un nouveau code. Les autres téléphones devront saisir celui-ci. */
  rotateHouseholdKey: (id: string) =>
    request<HouseholdWithKey>(`/households/${id}/access-key`, { method: 'POST' }),

  household: (id: string) => request<Household>(`/households/${id}`),

  updateHousehold: (id: string, body: { name?: string; dietaryExclusions?: string[] }) =>
    request<Household>(`/households/${id}`, { method: 'PATCH', body }),

  deleteHousehold: (id: string, confirmDeleteStock = false) =>
    request<void>(`/households/${id}`, { method: 'DELETE', body: { confirmDeleteStock } }),

  addMember: (householdId: string, firstName: string) =>
    request<Member>(`/households/${householdId}/members`, {
      method: 'POST',
      body: { firstName },
    }),

  removeMember: (householdId: string, memberId: string) =>
    request<void>(`/households/${householdId}/members/${memberId}`, { method: 'DELETE' }),

  reference: () => request<Reference>('/reference'),

  lookupBarcode: (barcode: string) =>
    request<{ found: boolean; product: Product }>(`/products/barcode/${barcode}`),

  stockItems: (filters: StockFilters = {}) => {
    const query = new URLSearchParams();
    if (filters.location) query.set('location', filters.location);
    if (filters.category) query.set('category', filters.category);
    if (filters.search) query.set('search', filters.search);
    if (filters.expiringWithinDays !== undefined) {
      query.set('expiringWithinDays', String(filters.expiringWithinDays));
    }
    if (filters.archived) query.set('archived', '1');
    const suffix = query.toString();

    return request<{ items: StockItem[] }>(`/stock-items${suffix ? `?${suffix}` : ''}`);
  },

  stockItem: (id: string) => request<StockItem>(`/stock-items/${id}`),

  /**
   * Un ajout crée un lot par date de péremption : la réponse est donc une liste.
   *
   * `operationKey` rend le geste rejouable sans effet supplémentaire : un
   * double appui, ou une réponse réseau perdue suivie d'une nouvelle tentative,
   * ne crée pas deux fois les mêmes lots.
   */
  createStockItem: (body: CreateStockItemInput, operationKey: string) =>
    request<{ items: StockItem[] }>('/stock-items', { method: 'POST', body: { ...body, operationKey } }),

  /**
   * `expectedRevision` — la révision lue avant l'envoi — protège une correction
   * de quantité contre l'écrasement d'un changement survenu entre-temps : le
   * serveur répond alors 409 plutôt que d'écraser silencieusement.
   */
  updateStockItem: (id: string, body: Record<string, unknown>, operationKey: string, expectedRevision?: number) =>
    request<StockItem>(`/stock-items/${id}`, { method: 'PATCH', body: { ...body, operationKey, expectedRevision } }),

  /**
   * Archive le lot : il quitte l'inventaire mais son journal survit, et le geste
   * se défait. Une suppression physique effaçait l'historique en cascade —
   * précisément dans le cas où il aurait servi.
   */
  archiveStockItem: (id: string, operationKey: string, reason?: string) =>
    request<StockItem>(`/stock-items/${id}`, { method: 'DELETE', body: { operationKey, reason } }),

  restoreStockItem: (id: string, operationKey: string) =>
    request<StockItem>(`/stock-items/${id}/restore`, { method: 'POST', body: { operationKey } }),

  /** Combien de temps cet aliment se garde-t-il, et pourquoi. */
  shelfLife: (params: {
    name: string;
    category?: string | null;
    storageLocation?: string;
    traits?: ProductTraitValue[];
  }) => {
    const query = new URLSearchParams({ name: params.name });
    if (params.category) query.set('category', params.category);
    if (params.storageLocation) query.set('storageLocation', params.storageLocation);
    if (params.traits?.length) query.set('traits', params.traits.join(','));

    return request<ShelfLifeEstimate>(`/products/shelf-life?${query.toString()}`);
  },

  consume: (id: string, body: Record<string, unknown>, operationKey: string) =>
    request<StockItem>(`/stock-items/${id}/consume`, { method: 'POST', body: { ...body, operationKey } }),

  /**
   * `purchaseDate`/`expirationDate` sont facultatifs : sans eux, l'appelant
   * affirme implicitement qu'il s'agit du même achat que ce lot (correction de
   * quantité). Fournis et différents de ce que porte déjà le lot, le serveur
   * refuse — un lot est homogène, un nouvel achat crée un nouveau lot plutôt
   * que de s'y mélanger.
   */
  addQuantity: (
    id: string,
    body: { quantity: number; unit?: Unit; purchaseDate?: string; expirationDate?: string },
    operationKey: string,
  ) => request<StockItem>(`/stock-items/${id}/add`, { method: 'POST', body: { ...body, operationKey } }),

  /** `expectedRevision`, si fourni, protège contre l'écrasement d'une correction survenue entre-temps. */
  correct: (id: string, body: Record<string, unknown>, operationKey: string, expectedRevision?: number) =>
    request<StockItem>(`/stock-items/${id}/correct`, { method: 'POST', body: { ...body, operationKey, expectedRevision } }),

  /**
   * Répare une unité mal choisie à la saisie.
   *
   * `quantity` n'est requis que pour changer de famille d'unité (g → unité) :
   * aucune conversion n'existe alors, le serveur refuse plutôt que de deviner.
   * `expectedRevision`, si fourni, protège contre une écriture périmée.
   */
  changeUnit: (id: string, unit: Unit, quantity: number | undefined, operationKey: string, expectedRevision?: number) =>
    request<StockItem>(`/stock-items/${id}/unit`, {
      method: 'POST',
      body: { unit, quantity, operationKey, expectedRevision },
    }),

  setOpened: (id: string, isOpened: boolean, operationKey: string) =>
    request<StockItem>(`/stock-items/${id}/open`, { method: 'POST', body: { isOpened, operationKey } }),

  /**
   * Corrige une erreur de saisie : annule l'ouverture comme si elle n'avait
   * jamais eu lieu. Distinct de `setOpened(id, false, …)` (« refermer »), qui
   * garde la trace de l'exemplaire entamé.
   */
  cancelOpening: (id: string, operationKey: string) =>
    request<StockItem>(`/stock-items/${id}/cancel-opening`, { method: 'POST', body: { operationKey } }),

  discard: (id: string, operationKey: string, reason?: string) =>
    request<StockItem>(`/stock-items/${id}/discard`, { method: 'POST', body: { reason, operationKey } }),

  movements: (id: string) =>
    request<{ movements: StockMovement[] }>(`/stock-items/${id}/movements`),

  inventory: () => request<InventorySummary>('/inventory/summary'),

  /**
   * `priorityKey` impose un aliment de l'inventaire aux trois propositions.
   *
   * Facultatif : sans lui, la génération reste celle d'avant. Renseigné, ce
   * n'est pas une préférence — le serveur écarte les recettes qui ne l'utilisent
   * pas plutôt que de se fier à la consigne donnée au modèle.
   */
  suggestRecipes: (body: {
    people: number;
    mealType?: string | null;
    maxMinutes?: number | null;
    equipment: string[];
    exclusions: string[];
    allowExtraIngredients: boolean;
    priorityKey?: string | null;
  }) => request<SuggestionResponse>('/recipes/suggest', { method: 'POST', body }),

  savedRecipes: () => request<{ recipes: SavedRecipe[] }>('/recipes/saved'),

  savedRecipe: (id: string) => request<SavedRecipe>(`/recipes/saved/${id}`),

  /**
   * Conserve une recette. Enregistrer deux fois la même n'en crée qu'une : le
   * serveur reconnaît le contenu à son empreinte.
   */
  saveRecipe: (recipe: Recipe) =>
    request<SavedRecipe>('/recipes/saved', { method: 'POST', body: { recipe } }),

  deleteSavedRecipe: (id: string) =>
    request<void>(`/recipes/saved/${id}`, { method: 'DELETE' }),

  /**
   * `operationKey` rend la confirmation rejouable sans effet supplémentaire.
   *
   * C'est ce qui manquait : la requête ne portait que le nom de la recette, donc
   * une réponse réseau perdue suivie d'une nouvelle tentative déduisait deux fois.
   * La clé reste la même pour une proposition donnée, y compris après un retour
   * en arrière ou une erreur.
   */
  confirmRecipe: (operationKey: string, recipeName: string, proposal: ConsumptionLine[]) =>
    request<ConfirmationResponse>('/recipes/confirm', {
      method: 'POST',
      body: { operationKey, recipeName, stockConsumptionProposal: proposal },
    }),
};
