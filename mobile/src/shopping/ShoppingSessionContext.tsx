import AsyncStorage from '@react-native-async-storage/async-storage';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

import { generateOperationKey } from '../api/client';
import type {
  FoodNature,
  GroceryReturnDecision,
  GroceryReturnProvenance,
  GroceryReturnStatus,
  Product,
  ProductCutValue,
  ProductTraitValue,
  ProductVariant,
  ReceiptIgnoredLine,
  ReceiptLineOrigin,
  ReceiptReading,
  ShoppingListItem,
  StorageLocation,
  Unit,
  UnitFamily,
} from '../api/types';
import { useSession } from '../session/SessionContext';
import { linesFromReceipt, mergeIgnored, sameIgnoredLine } from './triage';

/**
 * Un rechargement, un plantage ou la fermeture de l'application par Android
 * pendant les courses ne doit pas effacer ce qui a déjà été scanné : c'est
 * précisément le geste que ce panier existe pour éviter de ressaisir.
 *
 * Still version 1: every field added since (`touched`, `resolution`,
 * `decided`, the return key) is optional with a tolerant default, so a basket
 * saved before them loads as it is.
 */
const SCHEMA_VERSION = 1;

function storageKey(householdId: string): string {
  return `food.shoppingSession.v${SCHEMA_VERSION}.${householdId}`;
}

interface PersistedSession {
  schemaVersion: number;
  lines: ShoppingSessionLine[];
  /** One key for the whole basket; `null` until the first confirmation attempt. */
  returnOperationKey?: string | null;
  /** The receipt lines set aside as not food; absent on a basket saved before receipts. */
  ignoredReceiptLines?: ReceiptIgnoredLine[];
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
 * The fields the user can set by hand on the review. The resolution sends
 * `null` for the others and never overrides one of these once touched.
 */
export type ResolvableField = 'name' | 'unit' | 'quantityPerPackage' | 'storageLocation' | 'cut' | 'expirationDate';

/**
 * What the server answered for the line (see `GroceryReturnResolvedLine`),
 * kept apart from the editable values: the line shows and sends its own
 * fields, the resolution only says what they mean and what is still asked.
 */
export interface ShoppingSessionResolution {
  status: GroceryReturnStatus;
  decision: GroceryReturnDecision;
  decisionLabel: string | null;
  variantId: string | null;
  nature: FoodNature | null;
  unitFamily: UnitFamily;
  plausibleCuts: ProductCutValue[];
  cutMatters: boolean;
  /** The server's estimate, shown as « date estimée »; `null` when it could not estimate or a printed date was given. */
  estimatedExpirationDate: string | null;
  expirationExplanation: string | null;
  provenance: GroceryReturnProvenance;
}

/**
 * Une ligne du panier de « Retour de courses » — un produit distinct, pas
 * encore écrit en stock. Préremplie depuis les habitudes d'achat du foyer
 * (`householdVariant`) quand elles existent, sinon depuis le catalogue,
 * sinon laissée à saisir (code-barres inconnu). Then filled by the server's
 * resolution, which never overrides a field the user `touched`.
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
   * La forme de l'aliment, reprise de la variante du foyer quand elle existe,
   * sinon suggérée par le serveur (libellé, habitude du foyer, produit frais)
   * et modifiable sur l'écran de validation.
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
  /** A printed date, typed by the user. The server's estimate lives in `resolution`. */
  expirationDate: string | null;
  estimateExpiration: boolean;
  /** Renseigné pour répartir les exemplaires entre plusieurs dates ; sinon la ligne garde une seule date. */
  batches: ShoppingSessionBatch[] | null;
  /** Ce produit était attendu sur la liste de courses : retiré de la liste à la validation. */
  shoppingListItemId: string | null;
  /** The fields set by hand, in the order they were touched. Absent on a basket saved before it existed. */
  touched?: ResolvableField[];
  /** The last answer of `resolve` for this line; `null` or absent until the first one. */
  resolution?: ShoppingSessionResolution | null;
  /** The current decision was answered on the screen (a chip, « Garder l'estimation »): the line is ready. */
  decided?: boolean;
  /**
   * The printed line of the receipt this line came from. Sent back at the
   * confirmation so that the server learns what this label means at this
   * shop. `null` or absent for a scanned, typed or listed line.
   */
  receipt?: ReceiptLineOrigin | null;
  /** The household food a learnt receipt label named: the server's identity for the line until it is renamed. */
  variantId?: string | null;
}

interface ShoppingLookup {
  product: Product | null;
  householdVariant: ProductVariant | null;
  shoppingListItem?: ShoppingListItem | null;
}

/** A patch, or a function of the line as it is when the update applies — never of the render that asked (lesson 9). */
export type ShoppingLinePatch =
  | Partial<ShoppingSessionLine>
  | ((line: ShoppingSessionLine) => Partial<ShoppingSessionLine>);

let nextLineId = 1;

/** Every field at its default: a line to complete by hand. */
function newLine(overrides: Partial<ShoppingSessionLine>): ShoppingSessionLine {
  return {
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
    isUnknown: false,
    usualPackageSize: null,
    requestedQuantity: null,
    requestedUnit: null,
    expirationDate: null,
    estimateExpiration: false,
    batches: null,
    shoppingListItemId: null,
    touched: [],
    resolution: null,
    decided: false,
    receipt: null,
    variantId: null,
    ...overrides,
  };
}

interface ShoppingSessionState {
  lines: ShoppingSessionLine[];
  /** Une ligne par produit distinct : un code déjà présent incrémente son compte plutôt que d'en dupliquer une. */
  addScannedLine: (barcode: string, lookup: ShoppingLookup) => void;
  addManualLine: () => void;
  /**
   * One line per shopping list item — its label, its unit, nothing else: the
   * server resolves the rest from the list line. An item already in the
   * basket is skipped.
   */
  addListLines: (items: ShoppingListItem[]) => void;
  updateLine: (id: string, patch: ShoppingLinePatch) => void;
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
  /**
   * The lines of a receipt that are not food — said by the model, by a label
   * the household taught, or by « Ignorer » on the triage. Remembered by the
   * server at the confirmation; kept with the basket, cleared with it.
   */
  ignoredReceiptLines: ReceiptIgnoredLine[];
  /**
   * The foods of a receipt enter the basket already resolved, one line each,
   * and its other lines go to `ignoredReceiptLines`. Returns the lines added,
   * for the screen to say what came in.
   */
  addReceiptReading: (reading: ReceiptReading) => ShoppingSessionLine[];
  /** « C'est de la nourriture » : the entry becomes a basket line to resolve, with its printed label. */
  restoreIgnoredLine: (entry: ReceiptIgnoredLine) => void;
  /** « Ignorer (pas de la nourriture) » : the receipt line leaves the basket for `ignoredReceiptLines`. Nothing for a line without a printed label. */
  ignoreLine: (lineId: string) => void;
  clear: () => void;
  /**
   * The key of the whole basket's confirmation, stable until something is
   * written: a replay after a lost answer is recognised, a `422` or a
   * transport failure keeps it. Persisted with the basket.
   */
  returnOperationKey: () => string;
  /**
   * The lines a confirmation just wrote leave the basket, and the next attempt
   * gets a new key — the lines left to decide are another return. The basket
   * is cleared when nothing remains.
   */
  completeReturn: (lineIds: string[]) => void;
}

const ShoppingSessionContext = createContext<ShoppingSessionState | null>(null);

export function ShoppingSessionProvider({ children }: { children: React.ReactNode }) {
  const { household } = useSession();
  const householdId = household?.id ?? null;

  const [lines, setLines] = useState<ShoppingSessionLine[]>([]);
  const [ignoredReceiptLines, setIgnoredReceiptLines] = useState<ReceiptIgnoredLine[]>([]);
  const linesRef = useRef(lines);
  linesRef.current = lines;
  const ignoredRef = useRef(ignoredReceiptLines);
  ignoredRef.current = ignoredReceiptLines;
  const returnKey = useRef<string | null>(null);
  /**
   * Faux tant que la restauration asynchrone n'a pas résolu : sans ce garde,
   * l'effet de sauvegarde écrirait un panier vide par-dessus celui déjà
   * persisté avant même d'avoir eu le temps de le relire.
   */
  const hydrated = useRef(false);

  const persist = useCallback(
    (currentLines: ShoppingSessionLine[], currentIgnored: ReceiptIgnoredLine[]) => {
      if (!householdId || !hydrated.current) return;

      // An empty basket has nothing to restore: the entry goes, key included.
      if (0 === currentLines.length && 0 === currentIgnored.length) {
        AsyncStorage.removeItem(storageKey(householdId)).catch(() => undefined);
        return;
      }

      const payload: PersistedSession = {
        schemaVersion: SCHEMA_VERSION,
        lines: currentLines,
        returnOperationKey: returnKey.current,
        ignoredReceiptLines: currentIgnored,
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
      setIgnoredReceiptLines([]);
      returnKey.current = null;
      return;
    }

    let cancelled = false;

    AsyncStorage.getItem(storageKey(householdId))
      .then((raw) => {
        if (cancelled || !raw) return;

        const parsed = JSON.parse(raw) as PersistedSession;
        if (parsed.schemaVersion !== SCHEMA_VERSION) return;

        setLines(parsed.lines);
        setIgnoredReceiptLines(parsed.ignoredReceiptLines ?? []);
        returnKey.current = parsed.returnOperationKey ?? null;
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

  // A basket that became empty — last line removed, last scan undone — is
  // over: the next one is a new operation. Before the save below, so that the
  // reset is what gets persisted.
  useEffect(() => {
    if (hydrated.current && 0 === lines.length) returnKey.current = null;
  }, [lines]);

  // Sauvegarde après chaque modification — une fois la restauration terminée.
  useEffect(() => {
    persist(lines, ignoredReceiptLines);
  }, [lines, ignoredReceiptLines, persist]);

  const addScannedLine = useCallback((barcode: string, lookup: ShoppingLookup) => {
    setLines((current) => {
      const existing = current.find((line) => line.barcode === barcode);
      if (existing) {
        return current.map((line) =>
          line.id === existing.id ? { ...line, numberOfPackages: line.numberOfPackages + 1 } : line,
        );
      }

      const { product, householdVariant, shoppingListItem } = lookup;
      const fromList = {
        requestedQuantity: shoppingListItem?.quantity ?? null,
        requestedUnit: shoppingListItem?.unit ?? null,
        shoppingListItemId: shoppingListItem?.id ?? null,
      };

      if (householdVariant) {
        return [
          ...current,
          newLine({
            barcode,
            productId: product?.id ?? null,
            name: householdVariant.name,
            brand: householdVariant.brand,
            // La contenance vient toujours d'une habitude d'achat réelle ou du
            // catalogue — jamais d'une quantité demandée par la liste, qui est
            // un besoin (« 500 g de farine »), pas la taille du paquet acheté.
            unit: householdVariant.usualPackageUnit ?? product?.packageUnit ?? 'g',
            quantityPerPackage: String(householdVariant.usualPackageSize ?? product?.packageQuantity ?? ''),
            storageLocation: householdVariant.usualStorageLocation ?? 'pantry',
            traits: householdVariant.traits,
            cut: householdVariant.cut,
            isNew: false,
            usualPackageSize: householdVariant.usualPackageSize,
            ...fromList,
          }),
        ];
      }

      if (product) {
        return [
          ...current,
          newLine({
            barcode,
            productId: product.id,
            name: product.name,
            brand: product.brand,
            unit: product.packageUnit ?? 'g',
            quantityPerPackage: product.packageQuantity !== null ? String(product.packageQuantity) : '',
            traits: product.traits,
            ...fromList,
          }),
        ];
      }

      // Code-barres inconnu du catalogue partagé : ligne à compléter manuellement.
      return [...current, newLine({ barcode, isUnknown: true, ...fromList })];
    });
  }, []);

  const addManualLine = useCallback(() => {
    setLines((current) => [...current, newLine({ isUnknown: true })]);
  }, []);

  const addListLines = useCallback((items: ShoppingListItem[]) => {
    setLines((current) => {
      const present = new Set(current.map((line) => line.shoppingListItemId));
      const added = items
        .filter((item) => !present.has(item.id))
        .map((item) =>
          newLine({
            name: item.label,
            unit: item.unit,
            requestedQuantity: item.quantity,
            requestedUnit: item.unit,
            shoppingListItemId: item.id,
          }),
        );

      return 0 === added.length ? current : [...current, ...added];
    });
  }, []);

  const updateLine = useCallback((id: string, patch: ShoppingLinePatch) => {
    setLines((current) =>
      current.map((line) =>
        line.id === id ? { ...line, ...('function' === typeof patch ? patch(line) : patch) } : line,
      ),
    );
  }, []);

  const removeLine = useCallback((id: string) => {
    setLines((current) => current.filter((line) => line.id !== id));
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

      return current.filter((entry) => entry.id !== line.id);
    });
  }, []);

  const addReceiptReading = useCallback((reading: ReceiptReading): ShoppingSessionLine[] => {
    const added = linesFromReceipt(reading, newLine);
    if (added.length > 0) setLines((current) => [...current, ...added]);
    if (reading.ignored.length > 0) setIgnoredReceiptLines((current) => mergeIgnored(current, reading.ignored));

    return added;
  }, []);

  const restoreIgnoredLine = useCallback((entry: ReceiptIgnoredLine) => {
    setIgnoredReceiptLines((current) => current.filter((candidate) => !sameIgnoredLine(candidate, entry)));
    setLines((current) => [
      ...current,
      // Named as the model read it, or as printed: the server resolves the rest.
      newLine({
        name: entry.expandedName.trim() || entry.rawLabel,
        receipt: { store: entry.store, rawLabel: entry.rawLabel, lineTotal: null },
      }),
    ]);
  }, []);

  const ignoreLine = useCallback((lineId: string) => {
    const line = linesRef.current.find((candidate) => candidate.id === lineId);
    if (!line?.receipt) return;

    const { store, rawLabel } = line.receipt;
    setLines((current) => current.filter((candidate) => candidate.id !== lineId));
    setIgnoredReceiptLines((current) =>
      mergeIgnored(current, [{ store, rawLabel, expandedName: line.name.trim(), learned: false }]),
    );
  }, []);

  const clear = useCallback(() => {
    returnKey.current = null;
    // Abandon explicite : the save effect removes the entry of an empty basket.
    setLines([]);
    setIgnoredReceiptLines([]);
  }, []);

  const returnOperationKey = useCallback(() => {
    if (null === returnKey.current) {
      returnKey.current = generateOperationKey();
      // Ne modifie pas `lines` : sans cet appel explicite, la clé fraîchement
      // créée ne serait jamais persistée avant la prochaine modification du panier.
      persist(linesRef.current, ignoredRef.current);
    }

    return returnKey.current;
  }, [persist]);

  const completeReturn = useCallback((lineIds: string[]) => {
    returnKey.current = null;
    setLines((current) => current.filter((line) => !lineIds.includes(line.id)));
    // The whole basket was written and the labels set aside were learnt with
    // it: nothing of this receipt is left to show. Lines still to decide are
    // another return, which keeps them.
    if (linesRef.current.every((line) => lineIds.includes(line.id))) setIgnoredReceiptLines([]);
  }, []);

  const value = useMemo(
    () => ({
      lines,
      addScannedLine,
      addManualLine,
      addListLines,
      updateLine,
      removeLine,
      undoScan,
      ignoredReceiptLines,
      addReceiptReading,
      restoreIgnoredLine,
      ignoreLine,
      clear,
      returnOperationKey,
      completeReturn,
    }),
    [
      lines,
      addScannedLine,
      addManualLine,
      addListLines,
      updateLine,
      removeLine,
      undoScan,
      ignoredReceiptLines,
      addReceiptReading,
      restoreIgnoredLine,
      ignoreLine,
      clear,
      returnOperationKey,
      completeReturn,
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
