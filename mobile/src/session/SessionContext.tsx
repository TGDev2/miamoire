import * as SecureStore from 'expo-secure-store';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import { api, setIdentity, setUnauthorizedHandler } from '../api/client';
import { loadApiBaseUrl } from '../api/config';
import type { Household, Member } from '../api/types';
import { formatAccessKey } from './accessKey';

const HOUSEHOLD_KEY = 'food.householdId';
const MEMBER_KEY = 'food.memberId';
const ACCESS_KEY = 'food.householdKey';

interface SessionState {
  household: Household | null;
  member: Member | null;
  /** Le code du foyer, conservé pour pouvoir le repartager depuis le Profil. */
  accessKey: string | null;
  isLoading: boolean;
  /** Prêt à utiliser l'application : un foyer ouvert ET une personne identifiée. */
  isReady: boolean;
  enterHousehold: (household: Household, accessKey: string) => Promise<void>;
  /** Remplace le code après une régénération, sans quitter le foyer. */
  updateAccessKey: (accessKey: string) => Promise<void>;
  selectMember: (member: Member | null) => Promise<void>;
  refresh: () => Promise<void>;
  leave: () => Promise<void>;
}

const SessionContext = createContext<SessionState | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [household, setHousehold] = useState<Household | null>(null);
  const [member, setMember] = useState<Member | null>(null);
  const [accessKey, setAccessKey] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Le foyer et son code survivent au redémarrage : on ne redemande pas le code
  // à chaque ouverture, seulement à la première.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        await loadApiBaseUrl();

        const [householdId, memberId, storedKey] = await Promise.all([
          SecureStore.getItemAsync(HOUSEHOLD_KEY),
          SecureStore.getItemAsync(MEMBER_KEY),
          SecureStore.getItemAsync(ACCESS_KEY),
        ]);

        // Sans code, aucune requête n'aboutirait : autant repartir de l'accueil.
        if (!householdId || !storedKey) return;

        setIdentity(householdId, memberId, storedKey);
        const loaded = await api.household(householdId);
        if (cancelled) return;

        setHousehold(loaded);
        setAccessKey(storedKey);

        // Le membre a pu être supprimé du foyer entre deux ouvertures.
        const found = loaded.members.find((entry) => entry.id === memberId) ?? null;
        setMember(found);
        setIdentity(householdId, found?.id ?? null, storedKey);
        if (!found) await SecureStore.deleteItemAsync(MEMBER_KEY);
      } catch {
        // Foyer supprimé, code régénéré ailleurs, ou serveur injoignable :
        // on repart de l'écran de mise en route.
        setIdentity(null, null, null);
      } finally {
        if (!cancelled) setIsLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  /**
   * Entrer dans un foyer : à la création comme à l'adhésion, le code fait foi.
   *
   * Il est remis dans sa forme canonique avant d'être conservé : un code tapé à la
   * main serait sinon repartagé exactement comme il a été saisi, en minuscules et
   * sans tirets.
   */
  const enterHousehold = useCallback(async (next: Household, key: string) => {
    const canonical = formatAccessKey(key);

    await SecureStore.setItemAsync(HOUSEHOLD_KEY, next.id);
    await SecureStore.setItemAsync(ACCESS_KEY, canonical);
    await SecureStore.deleteItemAsync(MEMBER_KEY);
    setIdentity(next.id, null, canonical);
    setHousehold(next);
    setAccessKey(canonical);
    setMember(null);
  }, []);

  /**
   * L'identité est relue du stockage sécurisé, jamais de l'état de rendu.
   *
   * `enterHousehold` puis `selectMember` s'enchaînent dans la même action à la
   * création d'un foyer : la seconde s'exécuterait avec le `accessKey` du rendu
   * précédent — encore vide — et effacerait le code côté client. La requête
   * suivante partirait sans code, recevrait un 401, et déconnecterait aussitôt.
   */
  const storedIdentity = useCallback(async (memberId: string | null) => {
    const [householdId, key] = await Promise.all([
      SecureStore.getItemAsync(HOUSEHOLD_KEY),
      SecureStore.getItemAsync(ACCESS_KEY),
    ]);

    setIdentity(householdId, memberId, key);
  }, []);

  const selectMember = useCallback(
    async (next: Member | null) => {
      if (next) {
        await SecureStore.setItemAsync(MEMBER_KEY, next.id);
      } else {
        await SecureStore.deleteItemAsync(MEMBER_KEY);
      }
      setMember(next);
      await storedIdentity(next?.id ?? null);
    },
    [storedIdentity],
  );

  /**
   * Remplace le seul code, sans quitter le foyer ni oublier qui l'on est.
   *
   * Passer par `enterHousehold` remettrait le membre à zéro, ce qui renverrait
   * l'utilisateur vers l'écran de mise en route — et le nouveau code, affiché une
   * fois et une seule, serait perdu en chemin.
   */
  const updateAccessKey = useCallback(
    async (key: string) => {
      const canonical = formatAccessKey(key);

      await SecureStore.setItemAsync(ACCESS_KEY, canonical);
      setAccessKey(canonical);
      await storedIdentity(await SecureStore.getItemAsync(MEMBER_KEY));
    },
    [storedIdentity],
  );

  const refresh = useCallback(async () => {
    if (!household) return;

    const loaded = await api.household(household.id);
    setHousehold(loaded);

    const found = loaded.members.find((entry) => entry.id === member?.id) ?? null;
    setMember(found);
    await storedIdentity(found?.id ?? null);
  }, [household, member, storedIdentity]);

  const leave = useCallback(async () => {
    await SecureStore.deleteItemAsync(HOUSEHOLD_KEY);
    await SecureStore.deleteItemAsync(MEMBER_KEY);
    await SecureStore.deleteItemAsync(ACCESS_KEY);
    setIdentity(null, null, null);
    setHousehold(null);
    setMember(null);
    setAccessKey(null);
  }, []);

  // Un code devenu invalide — régénéré depuis un autre téléphone, foyer supprimé —
  // ramène à l'écran de mise en route au lieu de faire échouer chaque écran.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      void leave();
    });

    return () => setUnauthorizedHandler(null);
  }, [leave]);

  const value = useMemo(
    () => ({
      household,
      member,
      accessKey,
      isLoading,
      isReady: null !== household && null !== member,
      enterHousehold,
      updateAccessKey,
      selectMember,
      refresh,
      leave,
    }),
    [
      household,
      member,
      accessKey,
      isLoading,
      enterHousehold,
      updateAccessKey,
      selectMember,
      refresh,
      leave,
    ],
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
