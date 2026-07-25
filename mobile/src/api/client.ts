import { getApiBaseUrl } from './config';
import type {
  ConfirmationResponse,
  ConsumptionLine,
  Household,
  InventorySummary,
  Member,
  Product,
  Reference,
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
 * Identité courante : quel foyer, et qui agit.
 *
 * Il n'y a ni compte ni mot de passe — l'application est destinée à une seule
 * famille sur son réseau. Ces deux valeurs remplacent l'authentification :
 * le foyer isole le stock, le membre signe les mouvements.
 */
let currentHouseholdId: string | null = null;
let currentMemberId: string | null = null;

export function setIdentity(householdId: string | null, memberId: string | null): void {
  currentHouseholdId = householdId;
  currentMemberId = memberId;
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  /** Certains appels (liste des foyers) précèdent tout choix de foyer. */
  anonymous?: boolean;
};

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, anonymous = false } = options;
  const baseUrl = getApiBaseUrl();

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (!anonymous && currentHouseholdId) headers['X-Household'] = currentHouseholdId;
  if (!anonymous && currentMemberId) headers['X-Member'] = currentMemberId;

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
    throw new ApiError(message, response.status);
  }

  return payload as T;
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
  note?: string;
  quantityIsEstimated?: boolean;
}

export interface StockFilters {
  location?: string;
  category?: string;
  search?: string;
  expiringWithinDays?: number;
}

export const api = {
  households: () => request<{ households: Household[] }>('/households', { anonymous: true }),

  createHousehold: (name: string, firstName: string) =>
    request<Household>('/households', {
      method: 'POST',
      body: { name, firstName },
      anonymous: true,
    }),

  household: (id: string) => request<Household>(`/households/${id}`, { anonymous: true }),

  updateHousehold: (id: string, body: { name?: string; dietaryExclusions?: string[] }) =>
    request<Household>(`/households/${id}`, { method: 'PATCH', body }),

  deleteHousehold: (id: string, confirmDeleteStock = false) =>
    request<void>(`/households/${id}`, { method: 'DELETE', body: { confirmDeleteStock } }),

  addMember: (householdId: string, firstName: string) =>
    request<Member>(`/households/${householdId}/members`, {
      method: 'POST',
      body: { firstName },
      anonymous: true,
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
    const suffix = query.toString();

    return request<{ items: StockItem[] }>(`/stock-items${suffix ? `?${suffix}` : ''}`);
  },

  stockItem: (id: string) => request<StockItem>(`/stock-items/${id}`),

  createStockItem: (body: CreateStockItemInput) =>
    request<StockItem>('/stock-items', { method: 'POST', body }),

  updateStockItem: (id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}`, { method: 'PATCH', body }),

  deleteStockItem: (id: string) => request<void>(`/stock-items/${id}`, { method: 'DELETE' }),

  consume: (id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}/consume`, { method: 'POST', body }),

  addQuantity: (id: string, body: { quantity: number; unit?: Unit }) =>
    request<StockItem>(`/stock-items/${id}/add`, { method: 'POST', body }),

  correct: (id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}/correct`, { method: 'POST', body }),

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

  confirmRecipe: (recipeName: string, proposal: ConsumptionLine[]) =>
    request<ConfirmationResponse>('/recipes/confirm', {
      method: 'POST',
      body: { recipeName, stockConsumptionProposal: proposal },
    }),
};
