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
  Reference,
  ShelfLifeEstimate,
  StockItem,
  StockMovement,
  SuggestionResponse,
  Unit,
} from './types';

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
  const payload = text ? (JSON.parse(text) as unknown) : null;

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

  /** Un ajout crée un lot par date de péremption : la réponse est donc une liste. */
  createStockItem: (body: CreateStockItemInput) =>
    request<{ items: StockItem[] }>('/stock-items', { method: 'POST', body }),

  updateStockItem: (id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}`, { method: 'PATCH', body }),

  /**
   * Archive le lot : il quitte l'inventaire mais son journal survit, et le geste
   * se défait. Une suppression physique effaçait l'historique en cascade —
   * précisément dans le cas où il aurait servi.
   */
  archiveStockItem: (id: string, reason?: string) =>
    request<StockItem>(`/stock-items/${id}`, { method: 'DELETE', body: { reason } }),

  restoreStockItem: (id: string) =>
    request<StockItem>(`/stock-items/${id}/restore`, { method: 'POST' }),

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

  consume: (id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}/consume`, { method: 'POST', body }),

  addQuantity: (id: string, body: { quantity: number; unit?: Unit }) =>
    request<StockItem>(`/stock-items/${id}/add`, { method: 'POST', body }),

  correct: (id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}/correct`, { method: 'POST', body }),

  /**
   * Répare une unité mal choisie à la saisie.
   *
   * `quantity` n'est requis que pour changer de famille d'unité (g → unité) :
   * aucune conversion n'existe alors, le serveur refuse plutôt que de deviner.
   */
  changeUnit: (id: string, unit: Unit, quantity?: number) =>
    request<StockItem>(`/stock-items/${id}/unit`, { method: 'POST', body: { unit, quantity } }),

  setOpened: (id: string, isOpened: boolean) =>
    request<StockItem>(`/stock-items/${id}/open`, { method: 'POST', body: { isOpened } }),

  discard: (id: string, reason?: string) =>
    request<StockItem>(`/stock-items/${id}/discard`, { method: 'POST', body: { reason } }),

  movements: (id: string) =>
    request<{ movements: StockMovement[] }>(`/stock-items/${id}/movements`),

  inventory: () => request<InventorySummary>('/inventory/summary'),

  suggestRecipes: (body: {
    people: number;
    mealType?: string | null;
    maxMinutes?: number | null;
    equipment: string[];
    exclusions: string[];
    allowExtraIngredients: boolean;
  }) => request<SuggestionResponse>('/recipes/suggest', { method: 'POST', body }),

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
