import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createScanGate } from './scanGate.ts';

/** La cadence mesurée sur l'émulateur : une lecture toutes les 33 ms. */
const FRAME_MS = 33;

function gate() {
  return createScanGate({ confirmReads: 3, reappearGapMs: 1200 });
}

/** `count` lectures du même code à la cadence de la caméra ; rend les indices qui ont compté. */
function readInFrame({ counts }, code, from, count) {
  const counted = [];
  for (let index = 0; index < count; index++) {
    if (counts(code, from + index * FRAME_MS)) counted.push(index);
  }
  return counted;
}

test('une lecture isolée ne compte jamais', () => {
  const { counts } = gate();

  assert.equal(counts('6027670422028', 0), false);
});

test('un code compte à sa troisième lecture, une seule fois tant qu\'il reste dans le cadre', () => {
  // 60 lectures, deux secondes de cadrage : un seul geste.
  assert.deepEqual(readInFrame(gate(), '3017620422003', 0, 60), [2]);
});

test('un produit retiré puis représenté compte de nouveau', () => {
  const scanGate = gate();

  assert.deepEqual(readInFrame(scanGate, '3017620422003', 0, 10), [2]);
  // Hors du cadre plus longtemps que l'intervalle : c'est un second exemplaire.
  assert.deepEqual(readInFrame(scanGate, '3017620422003', 9 * FRAME_MS + 1300, 10), [2]);
});

test('une fausse lecture au milieu du vrai code ne compte pas', () => {
  const { counts } = gate();
  const counted = [];

  for (let index = 0; index < 30; index++) {
    const now = index * FRAME_MS;
    if (counts('3017620422003', now)) counted.push('3017620422003');
    // Le cas relevé sur l'émulateur : un code voisin, à clé valide, vu une fois.
    if (15 === index && counts('1018028012003', now + 1)) counted.push('1018028012003');
  }

  assert.deepEqual(counted, ['3017620422003']);
});

test('des lectures isolées, séparées de plus que l\'intervalle, ne s\'additionnent pas', () => {
  const { counts } = gate();

  assert.equal(counts('011970422006', 0), false);
  assert.equal(counts('011970422006', 1200), false);
  assert.equal(counts('011970422006', 2400), false);
});

test('un code oublié compte de nouveau sans quitter le cadre', () => {
  const scanGate = gate();

  assert.deepEqual(readInFrame(scanGate, '3017620422003', 0, 10), [2]);

  // Le geste « Annuler » : la ligne part du panier alors que le produit est
  // toujours devant la caméra. Sans l'oubli, les lectures suivantes ne comptent
  // plus et le représenter aussitôt pour se corriger serait sans effet.
  scanGate.forget('3017620422003');

  assert.deepEqual(readInFrame(scanGate, '3017620422003', 10 * FRAME_MS, 10), [2]);
});
