import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { api } from '../api/client';
import { loadApiBaseUrl } from '../api/config';
import type { CurrentUser } from '../api/types';

const TOKEN_KEY = 'food.jwt';

interface AuthState {
  token: string | null;
  user: CurrentUser | null;
  isLoading: boolean;
  signIn: (email: string, password: string) => Promise<void>;
  signUp: (input: { email: string; password: string; name: string; householdName?: string }) => Promise<void>;
  signOut: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Le jeton survit au redémarrage de l'application : on ne redemande pas
  // les identifiants à chaque ouverture.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        // L'adresse du serveur doit être connue avant le moindre appel réseau.
        await loadApiBaseUrl();

        const stored = await SecureStore.getItemAsync(TOKEN_KEY);
        if (stored) {
          const profile = await api.me(stored);
          if (!cancelled) {
            setToken(stored);
            setUser(profile);
          }
        }
      } catch {
        // Jeton expiré ou serveur indisponible : on repart d'une session vierge.
        await SecureStore.deleteItemAsync(TOKEN_KEY);
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const persist = useCallback(async (nextToken: string) => {
    await SecureStore.setItemAsync(TOKEN_KEY, nextToken);
    setToken(nextToken);
    setUser(await api.me(nextToken));
  }, []);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const { token: nextToken } = await api.login({ email: email.trim(), password });
      await persist(nextToken);
    },
    [persist],
  );

  const signUp = useCallback<AuthState['signUp']>(
    async (input) => {
      const { token: nextToken } = await api.register({ ...input, email: input.email.trim() });
      await persist(nextToken);
    },
    [persist],
  );

  const signOut = useCallback(async () => {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
    setToken(null);
    setUser(null);
  }, []);

  const refreshUser = useCallback(async () => {
    if (token) setUser(await api.me(token));
  }, [token]);

  const value = useMemo(
    () => ({ token, user, isLoading, signIn, signUp, signOut, refreshUser }),
    [token, user, isLoading, signIn, signUp, signOut, refreshUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth doit être utilisé à l\'intérieur d\'un AuthProvider.');
  }
  return context;
}

/** Raccourci pour les écrans protégés, où le jeton est nécessairement présent. */
export function useToken(): string {
  const { token } = useAuth();
  return token ?? '';
}
