/**
 * Ce qu'une lecture de la caméra doit être pour désigner un produit en rayon.
 *
 * Aucun import, et seulement de la syntaxe TypeScript effaçable : `npm test`
 * exécute ce fichier tel quel dans Node, sans compilation.
 */

/**
 * Les symbologies qui portent le GTIN d'un produit de consommation.
 *
 * Pas le Code 128 ni le Code 39 : ce sont des codes logistiques (cartons,
 * palettes), de longueur variable, le Code 39 sans clé de contrôle — ce sont
 * eux qui « lisaient » un texte imprimé ou des rayures d'emballage, et
 * ajoutaient au panier une ligne vide pour un produit que personne n'avait
 * scanné.
 */
export const RETAIL_BARCODE_TYPES = ['ean13', 'ean8', 'upc_a', 'upc_e'] as const;

type RetailBarcodeType = (typeof RETAIL_BARCODE_TYPES)[number];

const LENGTHS: Record<RetailBarcodeType, readonly number[]> = {
  ean13: [13],
  ean8: [8],
  upc_a: [12],
  // ML Kit ne documente pas la forme rendue : compressée ou développée en UPC-A.
  upc_e: [8, 12],
};

/**
 * Le code lu, s'il est un GTIN bien formé pour sa symbologie ; `null` sinon.
 *
 * Le natif Android transmet `barcode.value.toString()` : une valeur absente
 * arrive sous la forme de la chaîne `"null"`. Rien de ce que rend la caméra
 * n'est donc cru sur parole — longueur et clé de contrôle GS1 comprises.
 */
export function readRetailBarcode({ type, data }: { type: string; data: string }): string | null {
  if (!isRetailType(type) || !LENGTHS[type].includes(data.length) || !/^\d+$/.test(data)) {
    return null;
  }

  // Un UPC-E compressé porte la clé de sa forme développée, pas la sienne.
  const gtin = 'upc_e' === type && 8 === data.length ? expandUpcE(data) : data;

  return null !== gtin && hasValidCheckDigit(gtin) ? data : null;
}

function isRetailType(type: string): type is RetailBarcodeType {
  return (RETAIL_BARCODE_TYPES as readonly string[]).includes(type);
}

/** Clé GS1 (mod 10) : poids 3 puis 1 en alternance, depuis le chiffre qui précède la clé. */
function hasValidCheckDigit(gtin: string): boolean {
  const digits = [...gtin].map(Number);
  const check = digits.pop();
  const sum = digits.reverse().reduce((total, digit, index) => total + digit * (0 === index % 2 ? 3 : 1), 0);

  return (10 - (sum % 10)) % 10 === check;
}

/** UPC-E → UPC-A : le dernier chiffre du corps dit où replacer les zéros supprimés. */
function expandUpcE(code: string): string | null {
  const [system, d1, d2, d3, d4, d5, d6, check] = code;
  if ('0' !== system && '1' !== system) return null;

  let body: string;
  if (d6 <= '2') body = `${d1}${d2}${d6}0000${d3}${d4}${d5}`;
  else if ('3' === d6) body = `${d1}${d2}${d3}00000${d4}${d5}`;
  else if ('4' === d6) body = `${d1}${d2}${d3}${d4}00000${d5}`;
  else body = `${d1}${d2}${d3}${d4}${d5}0000${d6}`;

  return `${system}${body}${check}`;
}
