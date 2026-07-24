import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

const OVERRIDE_KEY = 'food.apiBaseUrl';

/**
 * Adresse par défaut de l'API.
 *
 * En développement, l'application tourne sur le téléphone et l'API sur l'ordinateur :
 * « localhost » désignerait le téléphone lui-même. On déduit donc l'adresse du
 * serveur de celle du bundler Expo. En build autonome, EXPO_PUBLIC_API_URL est
 * injectée à la compilation.
 */
function defaultBaseUrl(): string {
  const explicit = process.env.EXPO_PUBLIC_API_URL;
  if (explicit) {
    return normalize(explicit);
  }

  const hostUri =
    Constants.expoConfig?.hostUri ??
    (Constants.expoGoConfig as { debuggerHost?: string } | undefined)?.debuggerHost;
  const host = hostUri?.split(':')[0];

  return host ? `http://${host}:8000/api` : 'http://10.0.2.2:8000/api';
}

export const DEFAULT_API_BASE_URL = defaultBaseUrl();

let current = DEFAULT_API_BASE_URL;

export function getApiBaseUrl(): string {
  return current;
}

/**
 * L'adresse est modifiable dans l'application : selon le réseau, le téléphone
 * joint l'API par l'IP du PC (Wi-Fi) ou par `adb reverse` (USB, sans règle de
 * pare-feu). Un seul APK couvre les deux cas.
 */
export async function loadApiBaseUrl(): Promise<string> {
  try {
    const stored = await SecureStore.getItemAsync(OVERRIDE_KEY);
    if (stored) current = normalize(stored);
  } catch {
    // Stockage indisponible : l'adresse par défaut reste valable.
  }

  return current;
}

export async function setApiBaseUrl(url: string | null): Promise<string> {
  if (url === null || url.trim() === '') {
    current = DEFAULT_API_BASE_URL;
    await SecureStore.deleteItemAsync(OVERRIDE_KEY);
    return current;
  }

  current = normalize(url);
  await SecureStore.setItemAsync(OVERRIDE_KEY, current);

  return current;
}

/** Tolère « 192.168.1.61:8000 » comme « http://192.168.1.61:8000/api ». */
function normalize(raw: string): string {
  let value = raw.trim().replace(/\/+$/, '');

  if (!/^https?:\/\//i.test(value)) {
    value = `http://${value}`;
  }

  if (!/\/api$/i.test(value)) {
    value = `${value}/api`;
  }

  return value;
}
