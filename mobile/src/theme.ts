/**
 * Les jetons de l'interface.
 *
 * Une seule règle gouverne l'ensemble : **l'application n'a pas de couleur de
 * marque, la couleur dit le temps qui reste**. Le mobilier — fonds, cartes,
 * boutons, barre d'onglets, icônes — est entièrement en gris froids et en encre
 * bleu-noir. Les quatre teintes de `thermal` sont réservées aux échéances et
 * aux états qui en découlent. Une tache de couleur à l'écran veut donc toujours
 * dire « celui-là réclame quelque chose », et se voit avant d'être lue.
 */

import type { TextStyle } from 'react-native';

export const colors = {
  /** Le givre : le fond de tous les écrans. Froid, parce qu'on conserve au froid. */
  ground: '#E7ECEF',
  surface: '#FFFFFF',
  /** Les zones creuses : champs de saisie, pistes de sélection. */
  surfaceSunk: '#F1F4F6',
  /** L'encre : un noir bleuté, jamais neutre. */
  ink: '#0F171C',
  inkMuted: '#5A6B75',
  /** Filets d'un pixel — il n'y a aucune ombre dans cette interface. */
  border: '#CDD7DC',
  borderStrong: '#B3C1C8',
  onInk: '#FFFFFF',
} as const;

/**
 * L'échelle thermique : la seule couleur de l'application.
 *
 * `line` porte le texte, le rail et les filets ; `wash` est son fond très clair,
 * pour les pastilles. Les quatre `line` passent AA sur blanc (≥ 5:1).
 */
export const thermal = {
  /** Aucune échéance connue, ou trop lointaine pour compter. */
  calm: { line: '#5A6B75', wash: '#EAEFF1' },
  /** Il reste du temps : frais, scellé, réalisable. */
  safe: { line: '#176E7C', wash: '#E1EDEF' },
  /** Trois jours ou moins : entamé, à consommer vite, à compléter. */
  soon: { line: '#9A5B0C', wash: '#F6EBDB' },
  /**
   * Ce qui est perdu, ou sur le point de l'être : périmé, jeté, exclusion
   * enfreinte — et par extension les gestes qui retirent quelque chose. C'est
   * la seule teinte que l'interface se permet en dehors d'une échéance.
   */
  critical: { line: '#C9372C', wash: '#F9E4E2' },
} as const;

export type ThermalLevel = keyof typeof thermal;

/** Au-delà, la date exacte compte plus que le décompte : le cachet l'imprime. */
const DISTANT_DAYS = 60;

/** Le palier thermique d'une échéance, en jours. `null` = aucune date connue. */
export function thermalFor(days: number | null): ThermalLevel {
  if (null === days) return 'calm';
  if (days <= 0) return 'critical';
  if (days <= 3) return 'soon';
  if (days <= DISTANT_DAYS) return 'safe';

  return 'calm';
}

/**
 * Le cachet : ce qui s'imprime dans la pastille de chaque lot.
 *
 * `J−2`, `JOUR J`, `J+3` — la façon française de compter avant une échéance,
 * dans la voix machine de la date imprimée sur l'emballage. Le tilde de
 * `~J−5` dit que la date a été déduite d'une durée de conservation et non lue :
 * l'information existait déjà dans le modèle (`expirationIsEstimated`), elle
 * était noyée dans un « (estimé) » en fin de ligne grise.
 *
 * Au-delà de soixante jours, un décompte ne veut plus rien dire pour personne :
 * on imprime le mois, comme une DDM de conserve.
 */
export function stampLabel(days: number | null, date: string | null, estimated = false): string {
  if (null === days) return '—';

  const tilde = estimated ? '~' : '';

  if (0 === days) return `${tilde}JOUR J`;
  if (days < 0) return `${tilde}J+${Math.abs(days)}`;
  if (days <= DISTANT_DAYS) return `${tilde}J−${days}`;

  if (null !== date) {
    const [year, month] = date.split('-');
    return `${tilde}${month}.${year}`;
  }

  return `${tilde}J−${days}`;
}

/** La phrase complète du cachet, pour les lecteurs d'écran et les fiches. */
export function deadlineSentence(days: number | null, estimated = false): string {
  if (null === days) return 'Aucune date limite renseignée';

  if (estimated) {
    if (days < 0) return `À vérifier — estimé dépassé depuis ${Math.abs(days)} jours`;
    if (0 === days) return 'À vérifier aujourd\'hui, date estimée';
    if (1 === days) return 'À consommer demain environ, date estimée';
    return `À consommer sous ${days} jours environ, date estimée`;
  }

  if (days < 0) return `Périmé depuis ${Math.abs(days)} jour${Math.abs(days) > 1 ? 's' : ''}`;
  if (0 === days) return 'Périme aujourd\'hui';
  if (1 === days) return 'Périme demain';

  return `Périme dans ${days} jours`;
}

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

/** Rayons courts : l'interface est un instrument, pas une bulle. */
export const radius = {
  sm: 6,
  md: 10,
  lg: 12,
  pill: 999,
} as const;

/**
 * Deux familles, deux voix, une règle sans exception.
 *
 * **Archivo** parle la langue humaine : titres, noms d'aliments, phrases,
 * boutons. **Martian Mono** est l'imprimé machine : dates, nombres, unités,
 * compteurs, rubriques — jamais une phrase. Ce n'est pas un tic technique :
 * dans cette application, tout ce qui est chiffré est justement ce qui est
 * imprimé au jet d'encre sur l'emballage.
 *
 * Les graisses passent par `fontFamily` et jamais par `fontWeight` : Android
 * ignore `fontWeight` sur une police chargée et synthétise un faux gras.
 */
export const font = {
  sans: 'Archivo_400Regular',
  sansMedium: 'Archivo_500Medium',
  sansSemiBold: 'Archivo_600SemiBold',
  sansBold: 'Archivo_700Bold',
  mono: 'MartianMono_400Regular',
  monoMedium: 'MartianMono_500Medium',
  monoSemiBold: 'MartianMono_600SemiBold',
} as const;

export const text = {
  /** Le grand titre d'un onglet, dans le flux plutôt que dans une barre. */
  display: { fontFamily: font.sansBold, fontSize: 30, lineHeight: 35, letterSpacing: -0.7, color: colors.ink },
  title: { fontFamily: font.sansSemiBold, fontSize: 22, lineHeight: 28, letterSpacing: -0.4, color: colors.ink },
  heading: { fontFamily: font.sansSemiBold, fontSize: 17, lineHeight: 23, letterSpacing: -0.2, color: colors.ink },
  subheading: { fontFamily: font.sansMedium, fontSize: 15, lineHeight: 21, color: colors.ink },
  body: { fontFamily: font.sans, fontSize: 15, lineHeight: 22, color: colors.ink },
  bodyMuted: { fontFamily: font.sans, fontSize: 15, lineHeight: 22, color: colors.inkMuted },
  small: { fontFamily: font.sans, fontSize: 13, lineHeight: 19, color: colors.inkMuted },
  button: { fontFamily: font.sansSemiBold, fontSize: 15, lineHeight: 20, letterSpacing: -0.1 },

  /** Voix machine. */
  label: {
    fontFamily: font.monoMedium,
    fontSize: 10,
    lineHeight: 14,
    letterSpacing: 1.1,
    textTransform: 'uppercase',
    color: colors.inkMuted,
  },
  data: { fontFamily: font.mono, fontSize: 12, lineHeight: 17, color: colors.inkMuted },
  dataStrong: { fontFamily: font.monoMedium, fontSize: 12, lineHeight: 17, color: colors.ink },
  stamp: { fontFamily: font.monoSemiBold, fontSize: 12, lineHeight: 15 },
  stampLarge: { fontFamily: font.monoSemiBold, fontSize: 24, lineHeight: 30, letterSpacing: -0.8 },
} satisfies Record<string, TextStyle>;

/**
 * Les quatre créneaux d'un repas planifié, dans leur ordre chronologique du
 * jour — distinct de `mealType` (Cuisiner), qui catégorise une recette sans
 * ordonner une journée.
 */
export const mealSlotOptions: Array<{ value: 'breakfast' | 'lunch' | 'dinner' | 'snack'; label: string }> = [
  { value: 'breakfast', label: 'Petit-déjeuner' },
  { value: 'lunch', label: 'Déjeuner' },
  { value: 'dinner', label: 'Dîner' },
  { value: 'snack', label: 'Goûter' },
];

/**
 * Correspondance entre l'état renvoyé par l'API et sa présentation.
 *
 * `archived` manquait alors que le serveur le renvoie en priorité sur tous les
 * autres : un lot archivé s'affichait donc « Fermé », par le repli. Toute clé
 * de `StockItem::getStatus()` doit avoir son entrée ici.
 *
 * Les états qui parlent du temps (`expired`, `expiring_soon`) reprennent le
 * palier thermique correspondant : le cachet et la pastille ne peuvent pas se
 * contredire puisqu'ils lisent la même échelle.
 */
export const statusPresentation: Record<string, { label: string; level: ThermalLevel }> = {
  archived: { label: 'Archivé', level: 'calm' },
  expired: { label: 'Périmé', level: 'critical' },
  expiring_soon: { label: 'À consommer vite', level: 'soon' },
  running_low: { label: 'Bientôt terminé', level: 'soon' },
  opened: { label: 'Entamé', level: 'soon' },
  resealed: { label: 'Entamé et refermé', level: 'soon' },
  // Un produit frais n'a pas d'emballage : « Fermé » ne décrivait rien de réel
  // sur une courgette. Voir EffectiveUseByCalculator::status().
  fresh: { label: 'Frais', level: 'safe' },
  sealed: { label: 'Fermé', level: 'safe' },
  empty: { label: 'Épuisé', level: 'calm' },
};

/** Le conseiller avant les courses : une couleur par nature de conseil, jamais par confiance. */
export const shoppingAdviceKindPresentation: Record<string, ThermalLevel> = {
  use_soon_dont_add: 'soon',
  running_out: 'critical',
  dont_rebuy: 'safe',
  sufficient_for_planned_meals: 'safe',
};

export const feasibilityPresentation: Record<string, { label: string; level: ThermalLevel }> = {
  ready: { label: 'Réalisable maintenant', level: 'safe' },
  adapted: { label: 'Réalisable avec adaptations', level: 'safe' },
  needs_shopping: { label: 'Petit complément à acheter', level: 'soon' },
  // Ces trois-là viennent de la revérification serveur (`RecipePlan`), pas de
  // l'auto-évaluation de l'IA : elles priment sur les trois ci-dessus quand
  // les deux sont disponibles.
  needs_reassociation: { label: 'Aliment à réassocier', level: 'soon' },
  safety_unknown: { label: 'Sécurité non vérifiable', level: 'critical' },
  incompatible: { label: 'Ne respecte plus vos exclusions', level: 'critical' },
};
