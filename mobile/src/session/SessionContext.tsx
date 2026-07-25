import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { api, setIdentity } from '../api/client';
import { loadApiBaseUrl } from '../api/config';
import type { Household, Member } from '../api/types';

const HOUSEHOLD_KEY = 'food.householdId';
const MEMBER_KEY = 'food.memberId';

interface SessionState {
  household: Household | null;
  member: Member | null;
  isLoading: boolean;
  /** Prêt à utiliser l'application : un foyer choisi ET une personne identifiée. */
  isReady: boolean;
  selectHousehold: (household: Household) => Promise<void>;
  selectMember: (member: Member | null) => Promise<void>;
  refresh: () => Promise<void>;
  leave: () => Promise<void>;
}

const SessionContext = createContext<SessionState | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [household, setHousehold] = useState<Household | null>(null);
  const [member, setMember] = useState<Member | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Le choix survit au redémarrage : on ne redemande pas le foyer à chaque ouverture.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        await loadApiBaseUrl();

        const [householdId, memberId] = await Promise.all([
          SecureStore.getItemAsync(HOUSEHOLD_KEY),
          SecureStore.getItemAsync(MEMBER_KEY),
        ]);

        if (!householdId) return;

        setIdentity(householdId, memberId);
        const loaded = await api.household(householdId);
        if (cancelled) return;

        setHousehold(loaded);

        // Le membre a pu être supprimé du foyer entre deux ouvertures.
        const found = loaded.members.find((entry) => entry.id === memberId) ?? null;
        setMember(found);
        setIdentity(householdId, found?.id ?? null);
        if (!found) await SecureStore.deleteItemAsync(MEMBER_KEY);
      } catch {
        // Foyer supprimé ou serveur injoignable : on repart de l'écran de choix.
        setIdentity(null, null);
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const selectHousehold = useCallback(async (next: Household) => {
    await SecureStore.setItemAsync(HOUSEHOLD_KEY, next.id);
    await SecureStore.deleteItemAsync(MEMBER_KEY);
    setIdentity(next.id, null);
    setHousehold(next);
    setMember(null);
  }, []);

  const selectMember = useCallback(async (next: Member | null) => {
    if (next) {
      await SecureStore.setItemAsync(MEMBER_KEY, next.id);
    } else {
      await SecureStore.deleteItemAsync(MEMBER_KEY);
    }
    setMember(next);
    setIdentity(await SecureStore.getItemAsync(HOUSEHOLD_KEY), next?.id ?? null);
  }, []);

  const refresh = useCallback(async () => {
    if (!household) return;

    const loaded = await api.household(household.id);
    setHousehold(loaded);

    const found = loaded.members.find((entry) => entry.id === member?.id) ?? null;
    setMember(found);
    setIdentity(loaded.id, found?.id ?? null);
  }, [household, member]);

  const leave = useCallback(async () => {
    await SecureStore.deleteItemAsync(HOUSEHOLD_KEY);
    await SecureStore.deleteItemAsync(MEMBER_KEY);
    setIdentity(null, null);
    setHousehold(null);
    setMember(null);
  }, []);

  const value = useMemo(
    () => ({
      household,
      member,
      isLoading,
      isReady: null !== household && null !== member,
      selectHousehold,
      selectMember,
      refresh,
      leave,
    }),
    [household, member, isLoading, selectHousehold, selectMember, refresh, leave],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionState {
  const context = useContext(SessionContext);
  if (!context) {
    throw new Error('useSession doit être utilisé à l\'intérieur d\'un SessionProvider.');
  }
  return context;
}
