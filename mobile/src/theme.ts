/** Palette et échelles partagées par tous les écrans. */

export const colors = {
  background: '#FBF7F0',
  surface: '#FFFFFF',
  surfaceMuted: '#F2EDE3',
  border: '#E3DCCE',
  ink: '#1C1B17',
  inkMuted: '#7C7669',
  primary: '#2F6B4F',
  primarySoft: '#E4EEE8',
  accent: '#C2601F',
  accentSoft: '#FBEADF',
  danger: '#B03A2E',
  dangerSoft: '#F8E4E1',
  info: '#3D5A73',
  infoSoft: '#E5EBF1',
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 18,
  pill: 999,
} as const;

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
 */
export const statusPresentation: Record<string, { label: string; color: string; background: string }> = {
  archived: { label: 'Archivé', color: colors.inkMuted, background: colors.surfaceMuted },
  expired: { label: 'Périmé', color: colors.danger, background: colors.dangerSoft },
  expiring_soon: { label: 'À consommer vite', color: colors.accent, background: colors.accentSoft },
  running_low: { label: 'Bientôt terminé', color: colors.accent, background: colors.accentSoft },
  opened: { label: 'Entamé', color: colors.info, background: colors.infoSoft },
  resealed: { label: 'Entamé et refermé', color: colors.info, background: colors.infoSoft },
  sealed: { label: 'Fermé', color: colors.primary, background: colors.primarySoft },
  empty: { label: 'Épuisé', color: colors.inkMuted, background: colors.surfaceMuted },
};

/** Le conseiller avant les courses : une couleur par nature de conseil, jamais par confiance. */
export const shoppingAdviceKindPresentation: Record<string, { color: string; background: string }> = {
  use_soon_dont_add: { color: colors.accent, background: colors.accentSoft },
  running_out: { color: colors.danger, background: colors.dangerSoft },
  dont_rebuy: { color: colors.primary, background: colors.primarySoft },
  sufficient_for_planned_meals: { color: colors.primary, background: colors.primarySoft },
};

export const feasibilityPresentation: Record<string, { label: string; color: string; background: string }> = {
  ready: { label: 'Réalisable immédiatement', color: colors.primary, background: colors.primarySoft },
  adapted: { label: 'Réalisable avec adaptations', color: colors.info, background: colors.infoSoft },
  needs_shopping: { label: 'Petit complément à acheter', color: colors.accent, background: colors.accentSoft },
  // Ces trois-là viennent de la revérification serveur (`RecipePlan`), pas de
  // l'auto-évaluation de l'IA : elles priment sur les trois ci-dessus quand
  // les deux sont disponibles.
  needs_reassociation: { label: 'Aliment à réassocier', color: colors.accent, background: colors.accentSoft },
  safety_unknown: { label: 'Sécurité non vérifiable', color: colors.danger, background: colors.dangerSoft },
  incompatible: { label: 'Ne respecte plus vos exclusions', color: colors.danger, background: colors.dangerSoft },
};
