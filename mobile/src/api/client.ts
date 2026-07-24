import { getApiBaseUrl } from './config';
import type {
  ConfirmationResponse,
  ConsumptionLine,
  CurrentUser,
  InventorySummary,
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

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  token?: string | null;
};

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, token } = options;
  const baseUrl = getApiBaseUrl();

  let response: Response;
  try {
    response = await fetch(`${baseUrl}${path}`, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(
      `Serveur injoignable (${baseUrl}). Vérifiez que l'API tourne et corrigez au besoin son adresse ci-dessous.`,
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
  register: (body: { email: string; password: string; name: string; householdName?: string }) =>
    request<{ token: string; user: CurrentUser }>('/auth/register', { method: 'POST', body }),

  login: (body: { email: string; password: string }) =>
    request<{ token: string }>('/auth/login', { method: 'POST', body }),

  me: (token: string) => request<CurrentUser>('/me', { token }),

  updateProfile: (
    token: string,
    body: { name?: string; householdName?: string; dietaryExclusions?: string[] },
  ) => request<CurrentUser>('/me', { method: 'PATCH', body, token }),

  reference: (token: string) => request<Reference>('/reference', { token }),

  lookupBarcode: (token: string, barcode: string) =>
    request<{ found: boolean; product: Product }>(`/products/barcode/${barcode}`, { token }),

  stockItems: (token: string, filters: StockFilters = {}) => {
    const query = new URLSearchParams();
    if (filters.location) query.set('location', filters.location);
    if (filters.category) query.set('category', filters.category);
    if (filters.search) query.set('search', filters.search);
    if (filters.expiringWithinDays !== undefined) {
      query.set('expiringWithinDays', String(filters.expiringWithinDays));
    }
    const suffix = query.toString();

    return request<{ items: StockItem[] }>(`/stock-items${suffix ? `?${suffix}` : ''}`, { token });
  },

  stockItem: (token: string, id: string) => request<StockItem>(`/stock-items/${id}`, { token }),

  createStockItem: (token: string, body: CreateStockItemInput) =>
    request<StockItem>('/stock-items', { method: 'POST', body, token }),

  updateStockItem: (
    token: string,
    id: string,
    body: Record<string, unknown>,
  ) => request<StockItem>(`/stock-items/${id}`, { method: 'PATCH', body, token }),

  deleteStockItem: (token: string, id: string) =>
    request<void>(`/stock-items/${id}`, { method: 'DELETE', token }),

  consume: (token: string, id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}/consume`, { method: 'POST', body, token }),

  addQuantity: (token: string, id: string, body: { quantity: number; unit?: Unit }) =>
    request<StockItem>(`/stock-items/${id}/add`, { method: 'POST', body, token }),

  correct: (token: string, id: string, body: Record<string, unknown>) =>
    request<StockItem>(`/stock-items/${id}/correct`, { method: 'POST', body, token }),

  setOpened: (token: string, id: string, isOpened: boolean) =>
    request<StockItem>(`/stock-items/${id}/open`, { method: 'POST', body: { isOpened }, token }),

  discard: (token: string, id: string, reason?: string) =>
    request<StockItem>(`/stock-items/${id}/discard`, { method: 'POST', body: { reason }, token }),

  movements: (token: string, id: string) =>
    request<{ movements: StockMovement[] }>(`/stock-items/${id}/movements`, { token }),

  inventory: (token: string) => request<InventorySummary>('/inventory/summary', { token }),

  suggestRecipes: (
    token: string,
    body: {
      people: number;
      mealType?: string | null;
      maxMinutes?: number | null;
      equipment: string[];
      exclusions: string[];
      allowExtraIngredients: boolean;
    },
  ) => request<SuggestionResponse>('/recipes/suggest', { method: 'POST', body, token }),

  confirmRecipe: (token: string, recipeName: string, proposal: ConsumptionLine[]) =>
    request<ConfirmationResponse>('/recipes/confirm', {
      method: 'POST',
      body: { recipeName, stockConsumptionProposal: proposal },
      token,
    }),
};
