import React, { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';

import { generateOperationKey } from '../api/client';
import type { Product, ProductTraitValue, ProductVariant, StorageLocation, Unit } from '../api/types';

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
  /** Aucune habitude d'achat connue pour ce produit : à confirmer. */
  isNew: boolean;
  /** Code-barres inconnu du catalogue partagé : le nom reste à saisir. */
  isUnknown: boolean;
  /** Pour signaler un conditionnement différent de d'habitude — jamais bloquant. */
  usualPackageSize: number | null;
  expirationDate: string | null;
  estimateExpiration: boolean;
}

interface ShoppingLookup {
  product: Product | null;
  householdVariant: ProductVariant | null;
}

let nextLineId = 1;

interface ShoppingSessionState {
  lines: ShoppingSessionLine[];
  /** Une ligne par produit distinct : un code déjà présent incrémente son compte plutôt que d'en dupliquer une. */
  addScannedLine: (barcode: string, lookup: ShoppingLookup) => void;
  addManualLine: () => void;
  updateLine: (id: string, patch: Partial<ShoppingSessionLine>) => void;
  removeLine: (id: string) => void;
  clear: () => void;
  /** Clé d'opération stable par ligne, tant qu'elle n'a pas été validée. */
  operationKeyFor: (lineId: string) => string;
}

const ShoppingSessionContext = createContext<ShoppingSessionState | null>(null);

export function ShoppingSessionProvider({ children }: { children: React.ReactNode }) {
  const [lines, setLines] = useState<ShoppingSessionLine[]>([]);
  const operationKeys = useRef(new Map<string, string>());

  const addScannedLine = useCallback((barcode: string, lookup: ShoppingLookup) => {
    setLines((current) => {
      const existing = current.find((line) => line.barcode === barcode);
      if (existing) {
        return current.map((line) =>
          line.id === existing.id ? { ...line, numberOfPackages: line.numberOfPackages + 1 } : line,
        );
      }

      const { product, householdVariant } = lookup;

      if (householdVariant) {
        return [
          ...current,
          {
            id: String(nextLineId++),
            barcode,
            productId: product?.id ?? null,
            name: householdVariant.name,
            brand: householdVariant.brand,
            unit: householdVariant.usualPackageUnit ?? product?.packageUnit ?? 'g',
            quantityPerPackage: String(householdVariant.usualPackageSize ?? product?.packageQuantity ?? ''),
            numberOfPackages: 1,
            storageLocation: householdVariant.usualStorageLocation ?? 'pantry',
            traits: householdVariant.traits,
            isNew: false,
            isUnknown: false,
            usualPackageSize: householdVariant.usualPackageSize,
            expirationDate: null,
            estimateExpiration: false,
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
            isNew: true,
            isUnknown: false,
            usualPackageSize: null,
            expirationDate: null,
            estimateExpiration: false,
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
          isNew: true,
          isUnknown: true,
          usualPackageSize: null,
          expirationDate: null,
          estimateExpiration: false,
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
        isNew: true,
        isUnknown: true,
        usualPackageSize: null,
        expirationDate: null,
        estimateExpiration: false,
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

  const clear = useCallback(() => {
    setLines([]);
    operationKeys.current.clear();
  }, []);

  const operationKeyFor = useCallback((lineId: string) => {
    const existing = operationKeys.current.get(lineId);
    if (existing) return existing;

    const key = generateOperationKey();
    operationKeys.current.set(lineId, key);
    return key;
  }, []);

  const value = useMemo(
    () => ({ lines, addScannedLine, addManualLine, updateLine, removeLine, clear, operationKeyFor }),
    [lines, addScannedLine, addManualLine, updateLine, removeLine, clear, operationKeyFor],
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
