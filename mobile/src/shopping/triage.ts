import type {
  GroceryReturnConfirmLineInput,
  GroceryReturnLineInput,
  GroceryReturnResolvedLine,
  ReceiptIgnoredLine,
  ReceiptReading,
} from '../api/types';
import type { ResolvableField, ShoppingSessionLine } from './ShoppingSessionContext';

/**
 * The triage of a grocery return, without React: which lines still ask for
 * something, what the server is sent, what it answers, and what is written.
 *
 * Every rule here is the one the screen shows: a line that reads « rangée
 * d'avance » is exactly a line `confirmPayloadFor` can write.
 */

export function isTouched(line: ShoppingSessionLine, field: ResolvableField): boolean {
  return (line.touched ?? []).includes(field);
}

/** The line's touched fields, plus these: the resolution will keep them. */
export function touch(line: ShoppingSessionLine, ...fields: ResolvableField[]): ResolvableField[] {
  return Array.from(new Set([...(line.touched ?? []), ...fields]));
}

/** « 1,5 » → 1.5. `null` when empty or not a positive number. */
export function parseQuantity(value: string): number | null {
  const trimmed = value.trim();
  if ('' === trimmed) return null;

  const parsed = Number(trimmed.replace(',', '.'));
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

/** A count of copies typed in a batch: whole, at least one. */
export function parseCount(value: string): number {
  return Math.max(1, Math.round(Number(value.replace(',', '.')) || 1));
}

/** The copies of the line: the sum of its batches when it has some. */
export function countOf(line: ShoppingSessionLine): number {
  if (line.batches && line.batches.length > 0) {
    return line.batches.reduce((total, batch) => total + parseCount(batch.numberOfPackages), 0);
  }

  return line.numberOfPackages;
}

export interface DateIntent {
  /** A printed date only. */
  expirationDate: string | null;
  estimateExpiration: boolean;
}

/**
 * What the line says about its date: a printed one, « estimate it », or
 * « none » — the last only when the user cleared it by hand. An untouched
 * line asks for an estimate: that is what it shows.
 */
export function dateIntentOf(line: ShoppingSessionLine): DateIntent {
  if (null !== line.expirationDate && !line.estimateExpiration) {
    return { expirationDate: line.expirationDate, estimateExpiration: false };
  }

  if (isTouched(line, 'expirationDate') && !line.estimateExpiration) {
    return { expirationDate: null, estimateExpiration: false };
  }

  return { expirationDate: null, estimateExpiration: true };
}

/**
 * A line still asks for something when the server has not answered for it,
 * when it has no name or no usable quantity, or when its decision has not
 * been answered on the screen.
 */
export function needsDecision(line: ShoppingSessionLine): boolean {
  if (!line.resolution) return true;
  if ('' === line.name.trim()) return true;
  if (null === parseQuantity(line.quantityPerPackage)) return true;

  return 'none' !== line.resolution.decision && true !== line.decided;
}

export type LineGroup = 'toDecide' | 'ready';

export interface GroupOptions {
  /**
   * Lines kept in the group they are in, whatever their state, by line id: a
   * field of theirs has focus. A line that becomes ready at the first digit
   * of its quantity would otherwise jump to the other group, where its editor
   * is remounted and the next digits are lost (lesson 43). The move happens
   * when the field is left.
   */
  hold?: Partial<Record<string, LineGroup>>;
}

export function groupLines(
  lines: ShoppingSessionLine[],
  options: GroupOptions = {},
): {
  toDecide: ShoppingSessionLine[];
  ready: ShoppingSessionLine[];
} {
  const hold = options.hold ?? {};
  const toDecide: ShoppingSessionLine[] = [];
  const ready: ShoppingSessionLine[] = [];

  for (const line of lines) {
    const group = hold[line.id] ?? (needsDecision(line) ? 'toDecide' : 'ready');
    ('toDecide' === group ? toDecide : ready).push(line);
  }

  return { toDecide, ready };
}

/**
 * The line as `resolve` wants it: `null` for every field the user has not
 * set by hand, so the server fills it; the user's value otherwise, which the
 * server keeps. The name is always sent — it came from the server's own
 * lookup, or the user typed it, and a rename must never be lost.
 */
export function resolvePayloadFor(line: ShoppingSessionLine): GroceryReturnLineInput {
  const date = isTouched(line, 'expirationDate') ? dateIntentOf(line) : null;

  return {
    lineKey: line.id,
    barcode: line.barcode,
    productId: line.productId,
    shoppingListItemId: line.shoppingListItemId,
    name: line.name.trim(),
    brand: line.brand,
    unit: isTouched(line, 'unit') ? line.unit : null,
    quantityPerPackage: isTouched(line, 'quantityPerPackage') ? parseQuantity(line.quantityPerPackage) : null,
    numberOfPackages: countOf(line),
    storageLocation: isTouched(line, 'storageLocation') ? line.storageLocation : null,
    // Nothing on the review edits them: the server reads them off the food.
    traits: null,
    cut: isTouched(line, 'cut') ? line.cut : null,
    nature: null,
    expirationDate: date?.expirationDate ?? null,
    estimateExpiration: date?.estimateExpiration ?? null,
    // The food a learnt receipt label named. A name typed by hand says the
    // line is something else: the identity goes with it, the name decides.
    variantId: isTouched(line, 'name') ? null : (line.variantId ?? null),
  };
}

/**
 * The line as `confirm` writes it. Only for a line `needsDecision` lets
 * through: the name and the quantity are then set.
 */
export function confirmPayloadFor(line: ShoppingSessionLine): GroceryReturnConfirmLineInput {
  const date = dateIntentOf(line);
  const batches =
    line.batches && line.batches.length > 0
      ? line.batches.map((batch) => ({
          numberOfPackages: parseCount(batch.numberOfPackages),
          expirationDate: batch.estimateExpiration || !batch.expirationDate ? null : batch.expirationDate,
          estimateExpiration: batch.estimateExpiration,
        }))
      : null;

  return {
    lineKey: line.id,
    barcode: line.barcode,
    productId: line.productId,
    shoppingListItemId: line.shoppingListItemId,
    name: line.name.trim(),
    brand: line.brand,
    unit: line.unit,
    quantityPerPackage: parseQuantity(line.quantityPerPackage) ?? 0,
    numberOfPackages: countOf(line),
    storageLocation: line.storageLocation,
    traits: line.traits.length > 0 ? line.traits : null,
    // Never chosen on the review: sent, the server would pin it as a correction.
    nature: null,
    // Shown on the line before it is sent: what the user saw is what is saved.
    cut: line.cut,
    note: null,
    expirationDate: date.expirationDate,
    estimateExpiration: date.estimateExpiration,
    batches,
    // The printed line, so that the server learns what this label means here.
    receipt: line.receipt ? { store: line.receipt.store, rawLabel: line.receipt.rawLabel } : null,
  };
}

/**
 * The server's answer folded into the line. A field the user touched keeps
 * its value; the others take the server's. The answer to a decision survives
 * only while the server asks the same one.
 */
export function mergeResolved(
  line: ShoppingSessionLine,
  resolved: GroceryReturnResolvedLine,
): Partial<ShoppingSessionLine> {
  const sameDecision = line.resolution?.decision === resolved.decision;

  return {
    name: isTouched(line, 'name') || '' === resolved.name ? line.name : resolved.name,
    brand: resolved.brand ?? line.brand,
    productId: resolved.productId ?? line.productId,
    unit: isTouched(line, 'unit') ? line.unit : resolved.unit,
    quantityPerPackage:
      isTouched(line, 'quantityPerPackage') || null === resolved.quantityPerPackage
        ? line.quantityPerPackage
        : String(resolved.quantityPerPackage),
    storageLocation: isTouched(line, 'storageLocation') ? line.storageLocation : resolved.storageLocation,
    cut: isTouched(line, 'cut') ? line.cut : resolved.cut,
    traits: resolved.traits,
    isNew: null === resolved.variant,
    usualPackageSize: resolved.variant?.usualPackageSize ?? null,
    shoppingListItemId: line.shoppingListItemId ?? resolved.shoppingListItem?.id ?? null,
    requestedQuantity: resolved.shoppingListItem?.quantity ?? line.requestedQuantity,
    requestedUnit: resolved.shoppingListItem?.unit ?? line.requestedUnit,
    resolution: {
      status: resolved.status,
      decision: resolved.decision,
      decisionLabel: resolved.decisionLabel,
      variantId: resolved.variant?.id ?? null,
      nature: resolved.nature,
      unitFamily: resolved.unitFamily,
      plausibleCuts: resolved.plausibleCuts,
      cutMatters: resolved.cutMatters,
      estimatedExpirationDate: resolved.expirationIsEstimated ? resolved.expirationDate : null,
      expirationExplanation: resolved.expirationExplanation,
      provenance: resolved.provenance,
    },
    decided: 'none' !== resolved.decision && sameDecision && true === line.decided,
  };
}

/** The basket's own line factory: it owns the ids, this module only fills the fields. */
export type LineFactory = (overrides: Partial<ShoppingSessionLine>) => ShoppingSessionLine;

/**
 * The foods of a receipt as basket lines, one per printed line, already
 * resolved: the server answered for each as `resolve` would, so the line
 * arrives with its `resolution` and goes straight to its group. Nothing is
 * marked touched — the person set none of it — except the quantity and the
 * unit when the receipt printed them: a later resolve must keep them.
 */
export function linesFromReceipt(reading: ReceiptReading, newLine: LineFactory): ShoppingSessionLine[] {
  return reading.lines.map((entry) => {
    const printed = 'given' === entry.provenance.package && null !== entry.quantityPerPackage;
    const draft = newLine({
      barcode: entry.barcode,
      unit: entry.unit,
      quantityPerPackage: null === entry.quantityPerPackage ? '' : String(entry.quantityPerPackage),
      numberOfPackages: entry.numberOfPackages,
      isUnknown: 'unknown' === entry.status,
      touched: printed ? ['quantityPerPackage', 'unit'] : [],
      receipt: entry.receipt,
      variantId: entry.variant?.id ?? null,
    });

    return { ...draft, ...mergeResolved(draft, entry) };
  });
}

/** The receipt lines set aside, plus these — the same label at the same shop counted once. */
export function mergeIgnored(current: ReceiptIgnoredLine[], added: ReceiptIgnoredLine[]): ReceiptIgnoredLine[] {
  const merged = [...current];
  const seen = new Set(current.map(ignoredKey));

  for (const entry of added) {
    const key = ignoredKey(entry);
    if (seen.has(key)) continue;

    seen.add(key);
    merged.push(entry);
  }

  return merged;
}

/** `true` when both name the same printed line of the same shop. */
export function sameIgnoredLine(left: ReceiptIgnoredLine, right: { store: string | null; rawLabel: string }): boolean {
  return ignoredKey(left) === ignoredKey(right);
}

function ignoredKey(entry: { store: string | null; rawLabel: string }): string {
  return `${(entry.store ?? '').trim().toLowerCase()}|${entry.rawLabel.trim().toLowerCase()}`;
}
