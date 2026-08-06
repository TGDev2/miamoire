/**
 * Le retour physique de l'interface.
 *
 * L'application se manipule debout devant un frigo, bras tendu au-dessus d'un
 * chariot, ou les mains occupées : le retour visuel arrive souvent trop tard,
 * quand il arrive. Un geste qui a pris doit se sentir.
 *
 * Cinq intentions, jamais l'API brute dans un écran. Nommer l'intention plutôt
 * que la vibration permet d'en changer partout d'un seul endroit, et empêche
 * qu'un écran choisisse « Heavy » parce que son auteur trouvait ça plus net.
 *
 * Chaque appel est absorbé en cas d'échec : un téléphone sans vibreur, un iPhone
 * en économie d'énergie ou une permission refusée ne doivent jamais casser le
 * geste qu'ils accompagnent. C'est un ornement du réel, pas une étape du flux.
 */

import * as Haptics from 'expo-haptics';

function safely(run: () => Promise<unknown>): void {
  try {
    void run().catch(() => undefined);
  } catch {
    // Le module peut être absent d'un environnement de développement web.
  }
}

/** Un geste ordinaire a été pris en compte : un scan lu, une étape cochée. */
export function tap(): void {
  safely(() => Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light));
}

/** Un choix a changé : une puce, un filtre, une colonne de l'horizon. */
export function select(): void {
  safely(() => Haptics.selectionAsync());
}

/** Quelque chose est acquis : un lot ajouté, une recette confirmée. */
export function success(): void {
  safely(() => Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success));
}

/**
 * Quelque chose quitte le stock : consommé, jeté, terminé.
 *
 * Distinct de `success` parce que le geste est distinct : on retire. La
 * secousse est plus franche, pour que la main sache qu'elle vient d'engager
 * quelque chose — c'est le seul moment où la pastille d'annulation compte.
 */
export function removed(): void {
  safely(() => Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium));
}

/** L'action n'a pas abouti. Accompagne toujours un message écrit, jamais seule. */
export function failure(): void {
  safely(() => Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error));
}
