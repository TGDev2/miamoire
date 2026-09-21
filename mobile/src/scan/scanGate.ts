/**
 * Quand une suite de lectures de la caméra vaut un geste.
 *
 * Aucun import, et seulement de la syntaxe TypeScript effaçable : `npm test`
 * exécute ce fichier tel quel dans Node, sans compilation.
 */

/**
 * Une lecture isolée n'est pas un scan. De biais ou en mouvement, la caméra
 * rend parfois un code voisin de celui du produit, dont la clé de contrôle est
 * valide une fois sur dix : `readRetailBarcode` ne peut pas l'écarter. Mesuré
 * sur l'émulateur : 2 127 lectures du vrai code, une toutes les 33 ms, et trois
 * codes fantômes à clé valide — chacun vu une seule fois. Chacun ajoutait au
 * panier une ligne « Code inconnu » pour un produit que personne n'avait scanné.
 */
export const CONFIRM_READS = 3;

/**
 * En deçà de cette durée, une relecture du même code est le MÊME produit
 * encore dans le cadre (la caméra lit plusieurs fois par seconde tant qu'il y
 * reste). Au-delà, c'est un geste délibéré — le produit a été retiré puis
 * représenté — et il compte de nouveau : un exemplaire de plus.
 */
export const REAPPEAR_GAP_MS = 1200;

/**
 * `counts(code, now)` est vrai une fois par présentation d'un code : à sa
 * `confirmReads`-ième lecture sans interruption d'au moins `reappearGapMs`.
 */
export function createScanGate({
  confirmReads = CONFIRM_READS,
  reappearGapMs = REAPPEAR_GAP_MS,
}: { confirmReads?: number; reappearGapMs?: number } = {}): (code: string, now: number) => boolean {
  const presentations = new Map<string, { lastReadAt: number; reads: number }>();

  return function counts(code, now) {
    const current = presentations.get(code);
    const presentation =
      current && now - current.lastReadAt < reappearGapMs ? current : { lastReadAt: now, reads: 0 };

    presentation.lastReadAt = now;
    presentation.reads += 1;
    presentations.set(code, presentation);

    return presentation.reads === confirmReads;
  };
}
