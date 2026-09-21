// Lancé par `npm test` : `node --test` avec suppression des types, sans aucune
// dépendance. En `.mjs` pour rester hors des règles de `tsc`, qui ne vérifie
// pas le JavaScript de ce projet.
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { RETAIL_BARCODE_TYPES, readRetailBarcode } from './retailBarcode.ts';

test('le scanner ne cherche que les symbologies des produits en rayon', () => {
  assert.deepEqual([...RETAIL_BARCODE_TYPES], ['ean13', 'ean8', 'upc_a', 'upc_e']);
});

test('un code alimentaire valide est rendu tel quel', () => {
  assert.equal(readRetailBarcode({ type: 'ean13', data: '3017620422003' }), '3017620422003');
  assert.equal(readRetailBarcode({ type: 'ean8', data: '96385074' }), '96385074');
  assert.equal(readRetailBarcode({ type: 'upc_a', data: '036000291452' }), '036000291452');
});

test('un UPC-E est accepté compressé comme développé', () => {
  // La forme que rend ML Kit n'est pas documentée : les deux désignent le même
  // GTIN-12. 04252614 ↔ 042100005264 est l'exemple de référence de la norme.
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '04252614' }), '04252614');
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '042100005264' }), '042100005264');
});

test('la clé d\'un UPC-E compressé se vérifie sur sa forme développée', () => {
  // Une règle de développement par cas, chacun choisi pour que la clé diffère de
  // celle calculée sur les huit chiffres bruts — sans quoi oublier le
  // développement passerait inaperçu. (Pour un dernier chiffre de 5 à 9, les
  // deux calculs coïncident toujours : aucun cas ne peut les séparer.)
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '01234505' }), '01234505');
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '01234531' }), '01234531');
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '01234543' }), '01234543');
});

test('une valeur absente, que le natif Android transmet comme "null", est écartée', () => {
  assert.equal(readRetailBarcode({ type: 'ean13', data: 'null' }), null);
  assert.equal(readRetailBarcode({ type: 'ean13', data: '' }), null);
});

test('une lecture qui n\'est pas faite de chiffres est écartée', () => {
  assert.equal(readRetailBarcode({ type: 'ean13', data: '30176204220A3' }), null);
  // `Number(' ')` vaut 0 : sans contrôle des chiffres, cet espace passerait pour le zéro qu'il remplace.
  assert.equal(readRetailBarcode({ type: 'ean13', data: '3017620422 03' }), null);
});

test('une longueur qui ne correspond pas à la symbologie est écartée', () => {
  assert.equal(readRetailBarcode({ type: 'ean13', data: '96385074' }), null);
  assert.equal(readRetailBarcode({ type: 'ean8', data: '3017620422003' }), null);
  assert.equal(readRetailBarcode({ type: 'upc_a', data: '3017620422003' }), null);
});

test('une clé de contrôle fausse trahit une lecture partielle ou erronée', () => {
  assert.equal(readRetailBarcode({ type: 'ean13', data: '3017620422004' }), null);
  assert.equal(readRetailBarcode({ type: 'ean8', data: '96385075' }), null);
  assert.equal(readRetailBarcode({ type: 'upc_a', data: '036000291453' }), null);
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '04252615' }), null);
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '042100005265' }), null);
});

test('un UPC-E n\'existe qu\'avec un système de numérotation 0 ou 1', () => {
  // Clé valide une fois développé : seul le système de numérotation l'écarte.
  assert.equal(readRetailBarcode({ type: 'upc_e', data: '24252618' }), null);
});

test('un code logistique est écarté, même fait de chiffres valides', () => {
  assert.equal(readRetailBarcode({ type: 'code128', data: '3017620422003' }), null);
  assert.equal(readRetailBarcode({ type: 'code39', data: '3017620422003' }), null);
  assert.equal(readRetailBarcode({ type: 'qr', data: '3017620422003' }), null);
  // Une clé héritée d'Object.prototype n'est pas une symbologie.
  assert.equal(readRetailBarcode({ type: 'constructor', data: '3017620422003' }), null);
});
