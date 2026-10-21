import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
  confirmPayloadFor,
  dateIntentOf,
  groupLines,
  mergeResolved,
  resolvePayloadFor,
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
