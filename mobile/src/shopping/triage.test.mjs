import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
  confirmPayloadFor,
  dateIntentOf,
  groupLines,
  linesFromReceipt,
  mergeIgnored,
  mergeResolved,
  resolvePayloadFor,
  sameIgnoredLine,
  touch,
} from './triage.ts';

/** A line as the scanner adds it for a known product, before any resolution. */
function line(overrides = {}) {
  return {
    id: '1',
    barcode: '3017620422003',
    productId: 'product-1',
    name: 'Nutella',
    brand: 'Ferrero',
    unit: 'g',
    quantityPerPackage: '400',
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
    ...overrides,
  };
}

/** The server's answer for a line that asks nothing. */
function resolution(overrides = {}) {
  return {
    status: 'ready',
    decision: 'none',
    decisionLabel: null,
    variantId: 'variant-1',
    nature: 'packaged',
    unitFamily: 'mass',
    plausibleCuts: [],
    cutMatters: false,
    estimatedExpirationDate: '2026-11-05',
    expirationExplanation: 'Environ 2 semaines, durée usuelle au placard.',
    provenance: { storage: 'default', package: 'catalog', date: 'estimated' },
    ...overrides,
  };
}

/** The raw answer of `resolve`, as the API sends it. */
function resolved(overrides = {}) {
  return {
    lineKey: '1',
    status: 'ready',
    decision: 'none',
    decisionLabel: null,
    name: 'Nutella',
    brand: 'Ferrero',
    productId: 'product-1',
    barcode: '3017620422003',
    variant: null,
    shoppingListItem: null,
    unit: 'g',
    unitFamily: 'mass',
    quantityPerPackage: 400,
    numberOfPackages: 1,
    storageLocation: 'pantry',
    nature: 'packaged',
    natureLabel: 'Conditionné',
    traits: [],
    cut: null,
    plausibleCuts: [],
    cutMatters: false,
    expirationDate: '2026-11-05',
    expirationIsEstimated: true,
    expirationExplanation: 'Environ 2 semaines, durée usuelle au placard.',
    provenance: { storage: 'default', package: 'catalog', date: 'estimated' },
    ...overrides,
  };
}

const ready = () => line({ resolution: resolution() });

test('a line the server has not answered for still asks', () => {
  const { toDecide, ready: settled } = groupLines([line()]);

  assert.equal(toDecide.length, 1);
  assert.equal(settled.length, 0);
});

test('a resolved line with a name, a quantity and no decision is ready', () => {
  const { toDecide, ready: settled } = groupLines([ready()]);

  assert.equal(toDecide.length, 0);
  assert.equal(settled.length, 1);
});

test('no name or no usable quantity asks, whatever the server said', () => {
  const unnamed = line({ name: '  ', resolution: resolution() });
  const empty = line({ quantityPerPackage: '', resolution: resolution() });
  const zero = line({ quantityPerPackage: '0', resolution: resolution() });
  const letters = line({ quantityPerPackage: 'abc', resolution: resolution() });

  assert.equal(groupLines([unnamed, empty, zero, letters]).toDecide.length, 4);
});

test('a decision asks until it is answered on the screen', () => {
  const asked = line({ resolution: resolution({ decision: 'fridge_or_freezer', decisionLabel: 'Frigo ou congélateur ?' }) });
  const answered = { ...asked, decided: true };

  assert.equal(groupLines([asked]).toDecide.length, 1);
  assert.equal(groupLines([answered]).ready.length, 1);
});

test('grouping keeps the basket order inside each group', () => {
  const lines = [
    line({ id: '1', resolution: resolution() }),
    line({ id: '2' }),
    line({ id: '3', resolution: resolution() }),
    line({ id: '4', name: '' }),
  ];

  const groups = groupLines(lines);

  assert.deepEqual(groups.ready.map((entry) => entry.id), ['1', '3']);
  assert.deepEqual(groups.toDecide.map((entry) => entry.id), ['2', '4']);
});

test('an untouched line asks the server to estimate its date', () => {
  assert.deepEqual(dateIntentOf(line()), { expirationDate: null, estimateExpiration: true });
});

test('a printed date is sent as such, never estimated', () => {
  const dated = line({ expirationDate: '2026-11-05', estimateExpiration: false, touched: ['expirationDate'] });

  assert.deepEqual(dateIntentOf(dated), { expirationDate: '2026-11-05', estimateExpiration: false });
  assert.equal(confirmPayloadFor(dated).expirationDate, '2026-11-05');
  assert.equal(confirmPayloadFor(dated).estimateExpiration, false);
});

test('a date cleared by hand is neither sent nor estimated', () => {
  const cleared = line({ expirationDate: null, estimateExpiration: false, touched: ['expirationDate'] });

  assert.deepEqual(dateIntentOf(cleared), { expirationDate: null, estimateExpiration: false });
  assert.equal(confirmPayloadFor(cleared).expirationDate, null);
  assert.equal(confirmPayloadFor(cleared).estimateExpiration, false);
});

test('« Pas de date : estimer » chosen by hand asks for an estimate', () => {
  const estimated = line({ expirationDate: null, estimateExpiration: true, touched: ['expirationDate'] });

  assert.deepEqual(dateIntentOf(estimated), { expirationDate: null, estimateExpiration: true });
});

test('resolve sends null for every untouched field and the user value for touched ones', () => {
  const untouched = resolvePayloadFor(line());

  assert.equal(untouched.name, 'Nutella');
  assert.equal(untouched.unit, null);
  assert.equal(untouched.quantityPerPackage, null);
  assert.equal(untouched.storageLocation, null);
  assert.equal(untouched.cut, null);
  assert.equal(untouched.traits, null);
  assert.equal(untouched.nature, null);
  assert.equal(untouched.expirationDate, null);
  assert.equal(untouched.estimateExpiration, null);

  const touched = resolvePayloadFor(
    line({
      unit: 'kg',
      quantityPerPackage: '1,5',
      storageLocation: 'freezer',
      cut: 'slices',
      touched: ['unit', 'quantityPerPackage', 'storageLocation', 'cut'],
    }),
  );

  assert.equal(touched.unit, 'kg');
  assert.equal(touched.quantityPerPackage, 1.5);
  assert.equal(touched.storageLocation, 'freezer');
  assert.equal(touched.cut, 'slices');
});

test('resolve says when the user cleared the date, and when they typed one', () => {
  const cleared = resolvePayloadFor(line({ touched: ['expirationDate'] }));
  assert.equal(cleared.estimateExpiration, false);
  assert.equal(cleared.expirationDate, null);

  const printed = resolvePayloadFor(line({ expirationDate: '2026-11-05', touched: ['expirationDate'] }));
  assert.equal(printed.estimateExpiration, false);
  assert.equal(printed.expirationDate, '2026-11-05');
});

test('confirm writes the line as shown: cut, traits, trimmed name, parsed quantity', () => {
  const payload = confirmPayloadFor(
    line({ name: ' Nutella ', quantityPerPackage: '0,4', unit: 'kg', cut: 'whole', traits: ['sweetened'], resolution: resolution() }),
  );

  assert.equal(payload.name, 'Nutella');
  assert.equal(payload.quantityPerPackage, 0.4);
  assert.equal(payload.unit, 'kg');
  assert.equal(payload.cut, 'whole');
  assert.deepEqual(payload.traits, ['sweetened']);
  assert.equal(payload.nature, null);
  assert.equal(payload.note, null);
  assert.equal(payload.batches, null);
  assert.equal(payload.estimateExpiration, true);
});

test('confirm keeps « Dates différentes »: one batch per date, copies summed', () => {
  const payload = confirmPayloadFor(
    line({
      numberOfPackages: 1,
      batches: [
        { id: 1, numberOfPackages: '2', expirationDate: '2026-11-05', estimateExpiration: false },
        { id: 2, numberOfPackages: '3', expirationDate: null, estimateExpiration: true },
        { id: 3, numberOfPackages: 'x', expirationDate: null, estimateExpiration: false },
      ],
      resolution: resolution(),
    }),
  );

  assert.deepEqual(payload.batches, [
    { numberOfPackages: 2, expirationDate: '2026-11-05', estimateExpiration: false },
    { numberOfPackages: 3, expirationDate: null, estimateExpiration: true },
    { numberOfPackages: 1, expirationDate: null, estimateExpiration: false },
  ]);
  assert.equal(payload.numberOfPackages, 6);
});

test('the resolution fills untouched fields and keeps touched ones', () => {
  const edited = line({
    name: 'Pâte à tartiner',
    unit: 'kg',
    quantityPerPackage: '0,4',
    storageLocation: 'fridge',
    touched: ['name', 'unit', 'quantityPerPackage', 'storageLocation'],
  });

  const patch = mergeResolved(edited, resolved({ cut: 'whole', storageLocation: 'pantry', unit: 'g', quantityPerPackage: 400 }));

  assert.equal(patch.name, 'Pâte à tartiner');
  assert.equal(patch.unit, 'kg');
  assert.equal(patch.quantityPerPackage, '0,4');
  assert.equal(patch.storageLocation, 'fridge');
  assert.equal(patch.cut, 'whole');

  const fresh = mergeResolved(line({ name: '', quantityPerPackage: '' }), resolved({ storageLocation: 'fridge' }));

  assert.equal(fresh.name, 'Nutella');
  assert.equal(fresh.unit, 'g');
  assert.equal(fresh.quantityPerPackage, '400');
  assert.equal(fresh.storageLocation, 'fridge');
  assert.equal(fresh.resolution.estimatedExpirationDate, '2026-11-05');
  assert.equal(fresh.resolution.provenance.date, 'estimated');
});

test('the resolution never wipes a value with nothing', () => {
  const patch = mergeResolved(line(), resolved({ name: '', quantityPerPackage: null, brand: null }));

  assert.equal(patch.name, 'Nutella');
  assert.equal(patch.quantityPerPackage, '400');
  assert.equal(patch.brand, 'Ferrero');
});

test('a printed date given by the user is not an estimate', () => {
  const patch = mergeResolved(
    line({ expirationDate: '2026-11-05', touched: ['expirationDate'] }),
    resolved({ expirationDate: '2026-11-05', expirationIsEstimated: false, provenance: { storage: 'default', package: 'catalog', date: 'printed' } }),
  );

  assert.equal(patch.resolution.estimatedExpirationDate, null);
});

test('an answered decision survives a resolution that asks the same one, not another', () => {
  const answered = line({ resolution: resolution({ decision: 'short_date' }), decided: true });

  assert.equal(mergeResolved(answered, resolved({ decision: 'short_date' })).decided, true);
  assert.equal(mergeResolved(answered, resolved({ decision: 'unit_mismatch' })).decided, false);
  assert.equal(mergeResolved(answered, resolved({ decision: 'none' })).decided, false);
});

test('the matched list line and the household habit are folded into the line', () => {
  const patch = mergeResolved(
    line(),
    resolved({
      variant: { id: 'variant-1', usualPackageSize: 400 },
      shoppingListItem: { id: 'list-1', quantity: 2, unit: 'piece' },
    }),
  );

  assert.equal(patch.isNew, false);
  assert.equal(patch.usualPackageSize, 400);
  assert.equal(patch.shoppingListItemId, 'list-1');
  assert.equal(patch.requestedQuantity, 2);
  assert.equal(patch.requestedUnit, 'piece');
  assert.equal(patch.resolution.variantId, 'variant-1');
});

test('touch adds a field once', () => {
  assert.deepEqual(touch(line(), 'name'), ['name']);
  assert.deepEqual(touch(line({ touched: ['name'] }), 'name', 'unit'), ['name', 'unit']);
});

test('a line held by a focused field keeps its group whatever its state', () => {
  // Typing « 5 » of « 500 » made this one ready: it must not move under the finger.
  const typed = line({ id: '1', quantityPerPackage: '5', resolution: resolution() });
  // Backspacing through a quantity in a ready row's editor: not ready any more, but still being edited.
  const emptied = line({ id: '2', quantityPerPackage: '', resolution: resolution() });

  const free = groupLines([typed, emptied]);
  assert.deepEqual(free.ready.map((entry) => entry.id), ['1']);
  assert.deepEqual(free.toDecide.map((entry) => entry.id), ['2']);

  const held = groupLines([typed, emptied], { hold: { 1: 'toDecide', 2: 'ready' } });
  assert.deepEqual(held.toDecide.map((entry) => entry.id), ['1']);
  assert.deepEqual(held.ready.map((entry) => entry.id), ['2']);

  // A hold on a line that is not there changes nothing.
  assert.deepEqual(groupLines([typed], { hold: { 9: 'toDecide' } }).ready.map((entry) => entry.id), ['1']);
});

test('resolve names the learnt food until the line is renamed', () => {
  assert.equal(resolvePayloadFor(line()).variantId, null);
  assert.equal(resolvePayloadFor(line({ variantId: 'variant-1' })).variantId, 'variant-1');

  const renamed = line({ variantId: 'variant-1', name: 'Lait entier', touched: ['name'] });
  assert.equal(resolvePayloadFor(renamed).variantId, null);
  assert.equal(resolvePayloadFor(renamed).name, 'Lait entier');
});

const PRINTED = { store: 'E.LECLERC', rawLabel: 'LAIT 1/2 ECR 6X1L', lineTotal: 5.4 };

test('confirm sends the printed label of a receipt line, nothing for the others', () => {
  const printed = confirmPayloadFor(line({ receipt: PRINTED, resolution: resolution() }));

  assert.deepEqual(printed.receipt, { store: 'E.LECLERC', rawLabel: 'LAIT 1/2 ECR 6X1L' });
  assert.equal(confirmPayloadFor(ready()).receipt, null);
});

/** One food of a receipt, as `api.readReceipt` answers it: a resolved line plus its printed line. */
function receiptLine(overrides = {}, receipt = {}) {
  return {
    ...resolved({ lineKey: 'receipt-0', barcode: null, productId: null, ...overrides }),
    receipt: { ...PRINTED, ...receipt },
  };
}

function reading(overrides = {}) {
  return { store: 'E.LECLERC', receiptDate: '2026-10-20', total: 42.5, lines: [], ignored: [], ...overrides };
}

/** The basket's factory, as `newLine` does it: every field at its default, then the overrides. */
let minted = 0;
const mint = (overrides) =>
  line({ id: `r${++minted}`, barcode: null, productId: null, name: '', brand: null, quantityPerPackage: '', ...overrides });

test('a receipt becomes basket lines, resolved on arrival', () => {
  const lines = linesFromReceipt(
    reading({
      lines: [
        receiptLine({
          name: 'Lait demi-écrémé',
          brand: null,
          unit: 'l',
          unitFamily: 'volume',
          quantityPerPackage: 1,
          numberOfPackages: 6,
          storageLocation: 'fridge',
          variant: { id: 'variant-7', usualPackageSize: 1 },
          provenance: { storage: 'habit', package: 'given', date: 'estimated' },
        }),
        receiptLine(
          {
            lineKey: 'receipt-1',
            status: 'unknown',
            decision: 'unknown_food',
            decisionLabel: 'Quel est cet aliment ?',
            name: '',
            quantityPerPackage: null,
            expirationDate: null,
            expirationIsEstimated: false,
            provenance: { storage: 'default', package: 'none', date: 'none' },
          },
          { rawLabel: 'PDT 2KG', lineTotal: 3.2 },
        ),
      ],
    }),
    mint,
  );

  assert.equal(lines.length, 2);
  const [milk, unknown] = lines;

  assert.equal(milk.name, 'Lait demi-écrémé');
  assert.equal(milk.unit, 'l');
  assert.equal(milk.quantityPerPackage, '1');
  assert.equal(milk.numberOfPackages, 6);
  assert.equal(milk.storageLocation, 'fridge');
  assert.equal(milk.variantId, 'variant-7');
  assert.equal(milk.isNew, false);
  assert.equal(milk.usualPackageSize, 1);
  assert.deepEqual(milk.receipt, PRINTED);
  // The receipt printed the quantity and the unit: a later resolve keeps them.
  assert.deepEqual(milk.touched, ['quantityPerPackage', 'unit']);
  assert.equal(milk.resolution.decision, 'none');
  assert.equal(milk.resolution.variantId, 'variant-7');
  assert.equal(milk.resolution.estimatedExpirationDate, '2026-11-05');
  assert.equal(resolvePayloadFor(milk).quantityPerPackage, 1);
  assert.equal(resolvePayloadFor(milk).unit, 'l');
  assert.equal(resolvePayloadFor(milk).variantId, 'variant-7');

  assert.equal(unknown.name, '');
  assert.equal(unknown.quantityPerPackage, '');
  assert.deepEqual(unknown.touched, []);
  assert.equal(unknown.isUnknown, true);
  assert.equal(unknown.isNew, true);
  assert.equal(unknown.variantId, null);
  assert.equal(unknown.receipt.rawLabel, 'PDT 2KG');
  assert.equal(unknown.resolution.decision, 'unknown_food');

  // Straight to their groups: one ready, one asking — no second round trip.
  const groups = groupLines(lines);
  assert.deepEqual(groups.ready.map((entry) => entry.id), [milk.id]);
  assert.deepEqual(groups.toDecide.map((entry) => entry.id), [unknown.id]);
});

test('a quantity the receipt did not print is not touched: a later resolve may change it', () => {
  const [guessed] = linesFromReceipt(
    reading({ lines: [receiptLine({ provenance: { storage: 'default', package: 'catalog', date: 'estimated' } })] }),
    mint,
  );

  assert.equal(guessed.quantityPerPackage, '400');
  assert.deepEqual(guessed.touched, []);
  assert.equal(resolvePayloadFor(guessed).quantityPerPackage, null);
  assert.equal(resolvePayloadFor(guessed).unit, null);
});

test('ignored receipt lines are merged once per shop and label', () => {
  const batteries = { store: 'E.LECLERC', rawLabel: 'PILES AA X4', expandedName: 'Piles', learned: false };

  const merged = mergeIgnored(
    [batteries],
    [
      { ...batteries, store: 'e.leclerc ', rawLabel: 'piles aa x4', learned: true },
      { store: 'E.LECLERC', rawLabel: 'SAC CABAS', expandedName: 'Sac', learned: false },
    ],
  );

  assert.deepEqual(merged.map((entry) => entry.rawLabel), ['PILES AA X4', 'SAC CABAS']);
  assert.equal(merged[0].learned, false);
  assert.equal(sameIgnoredLine(batteries, { store: 'E.LECLERC', rawLabel: 'PILES AA X4' }), true);
  assert.equal(sameIgnoredLine(batteries, { store: 'CARREFOUR', rawLabel: 'PILES AA X4' }), false);
  assert.equal(sameIgnoredLine(batteries, { store: null, rawLabel: 'PILES AA X4' }), false);
});
