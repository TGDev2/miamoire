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
  sealed: { label: 'Fermé', color: colors.primary, background: colors.primarySoft },
  empty: { label: 'Épuisé', color: colors.inkMuted, background: colors.surfaceMuted },
};

export const feasibilityPresentation: Record<string, { label: string; color: string; background: string }> = {
  ready: { label: 'Réalisable immédiatement', color: colors.primary, background: colors.primarySoft },
  adapted: { label: 'Réalisable avec adaptations', color: colors.info, background: colors.infoSoft },
  needs_shopping: { label: 'Petit complément à acheter', color: colors.accent, background: colors.accentSoft },
};
