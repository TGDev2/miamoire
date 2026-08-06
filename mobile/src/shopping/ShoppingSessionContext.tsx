import AsyncStorage from '@react-native-async-storage/async-storage';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

import { generateOperationKey } from '../api/client';
import type {
  Product,
  ProductCutValue,
  ProductTraitValue,
  ProductVariant,
  ShoppingListItem,
  StorageLocation,
  Unit,
} from '../api/types';
import { useSession } from '../session/SessionContext';

/**
 * Un rechargement, un plantage ou la fermeture de l'application par Android
 * pendant les courses ne doit pas effacer ce qui a déjà été scanné : c'est
 * précisément le geste que ce panier existe pour éviter de ressaisir.
 */
const SCHEMA_VERSION = 1;

function storageKey(householdId: string): string {
  return `food.shoppingSession.v${SCHEMA_VERSION}.${householdId}`;
}

interface PersistedSession {
  schemaVersion: number;
  lines: ShoppingSessionLine[];
  /** `Map` ne se sérialise pas telle quelle : conservée comme objet simple. */
  operationKeys: Record<string, string>;
}

/**
 * Un exemplaire scanné plusieurs fois n'a pas forcément une seule date : trois
 * yaourts pour le 27 et deux pour le 3 août restent deux lots. Absent
 * (`null`), la ligne reste sur son unique `expirationDate` — le cas courant.
 */
export interface ShoppingSessionBatch {
  id: number;
  numberOfPackages: string;
  expirationDate: string | null;
  estimateExpiration: boolean;
}

/**
 * Une ligne du panier de « Retour de courses » — un produit distinct, pas
 * encore écrit en stock. Préremplie depuis les habitudes d'achat du foyer
 * (`householdVariant`) quand elles existent, sinon depuis le catalogue,
 * sinon laissée à saisir (code-barres inconnu).
 */
export interface ShoppingSessionLine {
  id: string;
  barcode: string | null;
  productId: string | null;
  name: string;
  brand: string | null;
  unit: Unit;
  /** Chaîne éditable, comme les champs de quantité de `add.tsx`. */
  quantityPerPackage: string;
  numberOfPackages: number;
  storageLocation: StorageLocation;
  traits: ProductTraitValue[];
  /**
   * La forme de l'aliment, reprise de la variante du foyer quand elle existe.
   *
   * Voyage sans interface dédiée, comme les traits : ce que le foyer a déjà
   * corrigé une fois ne doit pas être reperdu au retour de courses suivant.
   */
  cut: ProductCutValue | null;
  /** Aucune habitude d'achat connue pour ce produit : à confirmer. */
  isNew: boolean;
  /** Code-barres inconnu du catalogue partagé : le nom reste à saisir. */
  isUnknown: boolean;
  /** Pour signaler un conditionnement différent de d'habitude — jamais bloquant. */
  usualPackageSize: number | null;
  /**
   * Ce que la liste de courses demandait (un besoin, ex. « 500 g de farine »)
   * — jamais réutilisé comme contenance du produit acheté, qui vient toujours
   * de l'habitude d'achat ou du catalogue. Purement informatif.
   */
  requestedQuantity: number | null;
  requestedUnit: Unit | null;
  expirationDate: string | null;
  estimateExpiration: boolean;
  /** Renseigné pour répartir les exemplaires entre plusieurs dates ; sinon la ligne garde une seule date. */
  batches: ShoppingSessionBatch[] | null;
  /** Ce produit était attendu sur la liste de courses : retiré de la liste à la validation. */
  shoppingListItemId: string | null;
}

interface ShoppingLookup {
  product: Product | null;
  householdVariant: ProductVariant | null;
  shoppingListItem?: ShoppingListItem | null;
}

let nextLineId = 1;

interface ShoppingSessionState {
  lines: ShoppingSessionLine[];
  /** Une ligne par produit distinct : un code déjà présent incrémente son compte plutôt que d'en dupliquer une. */
  addScannedLine: (barcode: string, lookup: ShoppingLookup) => void;
  addManualLine: () => void;
  updateLine: (id: string, patch: Partial<ShoppingSessionLine>) => void;
  removeLine: (id: string) => void;
  /**
   * Défait exactement le dernier `addScannedLine` de ce code-barres : le compte
   * redescend d'un exemplaire, et la ligne disparaît si c'était le seul.
   *
   * L'erreur la plus probable d'un retour de courses est de scanner le mauvais
   * article, ou deux fois le même. Elle n'était réparable que depuis l'écran de
   * validation, deux navigations plus loin.
   */
  undoScan: (barcode: string) => void;
  clear: () => void;
  /** Clé d'opération stable par ligne, tant qu'elle n'a pas été validée. */
  operationKeyFor: (lineId: string) => string;
}

const ShoppingSessionContext = createContext<ShoppingSessionState | null>(null);

export function ShoppingSessionProvider({ children }: { children: React.ReactNode }) {
  const { household } = useSession();
  const householdId = household?.id ?? null;

  const [lines, setLines] = useState<ShoppingSessionLine[]>([]);
  const operationKeys = useRef(new Map<string, string>());
  /**
   * Faux tant que la restauration asynchrone n'a pas résolu : sans ce garde,
   * l'effet de sauvegarde écrirait un panier vide par-dessus celui déjà
   * persisté avant même d'avoir eu le temps de le relire.
   */
  const hydrated = useRef(false);

  const persist = useCallback(
    (currentLines: ShoppingSessionLine[]) => {
      if (!householdId || !hydrated.current) return;
      const payload: PersistedSession = {
        schemaVersion: SCHEMA_VERSION,
        lines: currentLines,
        operationKeys: Object.fromEntries(operationKeys.current),
      };
      AsyncStorage.setItem(storageKey(householdId), JSON.stringify(payload)).catch(() => undefined);
    },
    [householdId],
  );

  // Restaure le panier du foyer courant au montage / changement de foyer.
  useEffect(() => {
    hydrated.current = false;

    if (!householdId) {
      setLines([]);
      operationKeys.current.clear();
      return;
    }

    let cancelled = false;

    AsyncStorage.getItem(storageKey(householdId))
      .then((raw) => {
        if (cancelled || !raw) return;

        const parsed = JSON.parse(raw) as PersistedSession;
        if (parsed.schemaVersion !== SCHEMA_VERSION) return;

        setLines(parsed.lines);
        operationKeys.current = new Map(Object.entries(parsed.operationKeys ?? {}));
        nextLineId = Math.max(nextLineId, ...parsed.lines.map((line) => Number(line.id) + 1), 1);
      })
      .catch(() => undefined)
      .finally(() => {
        if (!cancelled) hydrated.current = true;
      });

    return () => {
      cancelled = true;
    };
  }, [householdId]);

  // Sauvegarde après chaque modification — une fois la restauration terminée.
  useEffect(() => {
    persist(lines);
  }, [lines, persist]);

  const addScannedLine = useCallback((barcode: string, lookup: ShoppingLookup) => {
    setLines((current) => {
      const existing = current.find((line) => line.barcode === barcode);
      if (existing) {
        return current.map((line) =>
          line.id === existing.id ? { ...line, numberOfPackages: line.numberOfPackages + 1 } : line,
        );
      }

      const { product, householdVariant, shoppingListItem } = lookup;

      if (householdVariant) {
        return [
          ...current,
          {
            id: String(nextLineId++),
            barcode,
            productId: product?.id ?? null,
            name: householdVariant.name,
            brand: householdVariant.brand,
            // La contenance vient toujours d'une habitude d'achat réelle ou du
            // catalogue — jamais d'une quantité demandée par la liste, qui est
            // un besoin (« 500 g de farine »), pas la taille du paquet acheté.
            unit: householdVariant.usualPackageUnit ?? product?.packageUnit ?? 'g',
            quantityPerPackage: String(
              householdVariant.usualPackageSize ?? product?.packageQuantity ?? '',
            ),
            numberOfPackages: 1,
            storageLocation: householdVariant.usualStorageLocation ?? 'pantry',
            traits: householdVariant.traits,
            cut: householdVariant.cut,
            isNew: false,
            isUnknown: false,
            usualPackageSize: householdVariant.usualPackageSize,
            requestedQuantity: shoppingListItem?.quantity ?? null,
            requestedUnit: shoppingListItem?.unit ?? null,
            expirationDate: null,
            estimateExpiration: false,
            batches: null,
            shoppingListItemId: shoppingListItem?.id ?? null,
          },
        ];
      }

      if (product) {
        return [
          ...current,
          {
            id: String(nextLineId++),
            barcode,
            productId: product.id,
            name: product.name,
            brand: product.brand,
            unit: product.packageUnit ?? 'g',
            quantityPerPackage: product.packageQuantity !== null ? String(product.packageQuantity) : '',
            numberOfPackages: 1,
            storageLocation: 'pantry',
            traits: product.traits,
            cut: null,
            isNew: true,
            isUnknown: false,
            usualPackageSize: null,
            requestedQuantity: shoppingListItem?.quantity ?? null,
            requestedUnit: shoppingListItem?.unit ?? null,
            expirationDate: null,
            estimateExpiration: false,
            batches: null,
            shoppingListItemId: shoppingListItem?.id ?? null,
          },
        ];
      }

      // Code-barres inconnu du catalogue partagé : ligne à compléter manuellement.
      return [
        ...current,
        {
          id: String(nextLineId++),
          barcode,
          productId: null,
          name: '',
          brand: null,
          unit: 'g',
          quantityPerPackage: '',
          numberOfPackages: 1,
          storageLocation: 'pantry',
          traits: [],
          cut: null,
          isNew: true,
          isUnknown: true,
          usualPackageSize: null,
          requestedQuantity: shoppingListItem?.quantity ?? null,
          requestedUnit: shoppingListItem?.unit ?? null,
          expirationDate: null,
          estimateExpiration: false,
          batches: null,
          shoppingListItemId: shoppingListItem?.id ?? null,
        },
      ];
    });
  }, []);

  const addManualLine = useCallback(() => {
    setLines((current) => [
      ...current,
      {
        id: String(nextLineId++),
        barcode: null,
        productId: null,
        name: '',
        brand: null,
        unit: 'g',
        quantityPerPackage: '',
        numberOfPackages: 1,
        storageLocation: 'pantry',
        traits: [],
        cut: null,
        isNew: true,
        isUnknown: true,
        usualPackageSize: null,
        requestedQuantity: null,
        requestedUnit: null,
        expirationDate: null,
        estimateExpiration: false,
        batches: null,
        shoppingListItemId: null,
      },
    ]);
  }, []);

  const updateLine = useCallback((id: string, patch: Partial<ShoppingSessionLine>) => {
    setLines((current) => current.map((line) => (line.id === id ? { ...line, ...patch } : line)));
  }, []);

  const removeLine = useCallback((id: string) => {
    setLines((current) => current.filter((line) => line.id !== id));
    operationKeys.current.delete(id);
  }, []);

  const undoScan = useCallback((barcode: string) => {
    setLines((current) => {
      const line = current.find((entry) => entry.barcode === barcode);
      if (undefined === line) return current;

      if (line.numberOfPackages > 1) {
        return current.map((entry) =>
          entry.id === line.id ? { ...entry, numberOfPackages: entry.numberOfPackages - 1 } : entry,
        );
      }

      operationKeys.current.delete(line.id);
      return current.filter((entry) => entry.id !== line.id);
    });
  }, []);

  const clear = useCallback(() => {
    setLines([]);
    operationKeys.current.clear();
    // Abandon explicite : rien à restaurer au prochain lancement.
    if (householdId) AsyncStorage.removeItem(storageKey(householdId)).catch(() => undefined);
  }, [householdId]);

  const operationKeyFor = useCallback(
    (lineId: string) => {
      const existing = operationKeys.current.get(lineId);
      if (existing) return existing;

      const key = generateOperationKey();
      operationKeys.current.set(lineId, key);
      // Ne modifie pas `lines` : sans cet appel explicite, la clé fraîchement
      // créée ne serait jamais persistée avant la prochaine modification du panier.
      persist(lines);
      return key;
    },
    [lines, persist],
  );

  const value = useMemo(
    () => ({
      lines,
      addScannedLine,
      addManualLine,
      updateLine,
      removeLine,
      undoScan,
      clear,
      operationKeyFor,
    }),
    [
      lines,
      addScannedLine,
      addManualLine,
      updateLine,
      removeLine,
      undoScan,
      clear,
      operationKeyFor,
    ],
  );

  return <ShoppingSessionContext.Provider value={value}>{children}</ShoppingSessionContext.Provider>;
}

export function useShoppingSession(): ShoppingSessionState {
  const context = useContext(ShoppingSessionContext);
  if (!context) {
    throw new Error('useShoppingSession doit être utilisé à l\'intérieur d\'un ShoppingSessionProvider.');
  }
  return context;
}
