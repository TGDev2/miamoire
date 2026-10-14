/**
 * L'horizon : la forme de la semaine qui vient.
 *
 * L'application savait dire le temps d'un lot — `J−2`, imprimé sur chaque ligne —
 * et jamais celui d'une semaine. Or la question qu'on se pose devant un frigo
 * n'est pas seulement « celui-là tient-il ? » mais « qu'est-ce qui m'attend ? ».
 * Un jeudi à quatre péremptions ne se voyait nulle part avant d'être un jeudi.
 *
 * Ce n'est pas un graphique posé sur un écran : c'est **le filtre le plus utile
 * du stock, dessiné avec ses propres données**. La hauteur porte le nombre de
 * lots, la couleur reprend l'échelle thermique — donc la bande se lit
 * naturellement de gauche à droite, du rouge de ce qui est perdu au bleu de ce
 * qui tient. Toucher une colonne filtre la liste sur ce jour.
 *
 * Sept jours, et rien au-delà. Ce qui périme dans trois semaines n'a pas sa
 * place dans un écran qui demande « que faire maintenant », et l'inclure
 * écraserait l'échelle : le paquet de riz noierait les trois yaourts.
 */

import React, { useMemo } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { StockItem } from '../api/types';
import { deadlineOf } from '../deadline';
import { colors, font, radius, spacing, text, thermal, thermalFor, type ThermalLevel } from '../theme';

/** Aujourd'hui compris. Au-delà, la date exacte compte plus que le décompte. */
const HORIZON_DAYS = 7;

const MAX_BAR = 34;
const MIN_BAR = 4;

/**
 * Ce qu'une colonne sélectionne : un décalage en jours, ou tout ce qui est déjà
 * dépassé. `null` — aucun filtre de jour.
 */
export type HorizonSelection = number | 'overdue' | null;

/**
 * La règle de rangement, écrite une seule fois.
 *
 * L'écran de stock l'emploie pour filtrer sa liste et cette bande pour compter
 * ses colonnes : les deux ne peuvent donc pas diverger — une colonne annonçant
 * trois lots ouvre toujours sur exactement ces trois lots.
 */
export function matchesHorizon(item: StockItem, selection: HorizonSelection): boolean {
  if (null === selection) return true;

  // In the freezer the date is a quality limit, not a deadline: frozen lots
  // are never "due" on a given day, nor "already expired".
  const { days, basis } = deadlineOf(item);
  if (null === days || 'frozen' === basis) return false;

  return 'overdue' === selection ? days < 0 : days === selection;
}

interface Column {
  selection: Exclude<HorizonSelection, null>;
  label: string;
  count: number;
  level: ThermalLevel;
  accessibilityLabel: string;
}

/** « jeu. » → « JE ». Deux lettres suffisent, et la locale fait le travail. */
function weekdayInitials(date: Date): string {
  return date.toLocaleDateString('fr-FR', { weekday: 'short' }).slice(0, 2).toUpperCase();
}

function fullDayLabel(date: Date): string {
  return date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
}

function lots(count: number): string {
  return 0 === count ? 'aucun lot' : `${count} lot${count > 1 ? 's' : ''}`;
}

export function Horizon({
  items,
  selection,
  onSelect,
}: {
  /** L'inventaire ENTIER, jamais la liste affichée : la bande dit le stock, pas l'écran. */
  items: StockItem[];
  selection: HorizonSelection;
  onSelect: (selection: HorizonSelection) => void;
}) {
  const columns = useMemo<Column[]>(() => {
    const counts = new Map<number, number>();
    let overdue = 0;

    for (const item of items) {
      const { days, basis } = deadlineOf(item);
      if (null === days || 'frozen' === basis) continue;

      if (days < 0) overdue += 1;
      else if (days < HORIZON_DAYS) counts.set(days, (counts.get(days) ?? 0) + 1);
    }

    const today = new Date();

    const days = Array.from({ length: HORIZON_DAYS }, (unused, offset) => {
      const date = new Date(today);
      date.setDate(today.getDate() + offset);
      const count = counts.get(offset) ?? 0;

      return {
        selection: offset,
        label: 0 === offset ? 'AUJ' : weekdayInitials(date),
        count,
        level: thermalFor(offset),
        accessibilityLabel: `${0 === offset ? "Aujourd'hui" : fullDayLabel(date)}, ${lots(count)}`,
      } satisfies Column;
    });

    // La colonne des périmés n'apparaît que lorsqu'il y a quelque chose à y
    // mettre : une colonne « 0 périmé » en permanence est du bruit, et le jour
    // où elle s'allume est justement un événement.
    return overdue > 0
      ? [
          {
            selection: 'overdue' as const,
            label: 'J+',
            count: overdue,
            level: 'critical' as const,
            accessibilityLabel: `Déjà périmés, ${lots(overdue)}`,
          },
          ...days,
        ]
      : days;
  }, [items]);

  const peak = Math.max(...columns.map((column) => column.count), 1);

  return (
    <View style={styles.card}>
      <View style={styles.head}>
        <Text style={text.label}>Sept jours</Text>
        {null === selection ? null : (
          <Pressable
            onPress={() => onSelect(null)}
            hitSlop={10}
            accessibilityRole="button"
            accessibilityLabel="Voir tout le stock"
            style={({ pressed }) => [styles.reset, pressed && styles.resetPressed]}
          >
            <Text style={[text.label, styles.resetLabel]}>Tout voir</Text>
          </Pressable>
        )}
      </View>

      <View style={styles.strip}>
        {columns.map((column) => {
          const active = selection === column.selection;
          const palette = thermal[column.level];
          const height =
            0 === column.count ? 0 : Math.max(MIN_BAR, Math.round((column.count / peak) * MAX_BAR));

          return (
            <Pressable
              key={String(column.selection)}
              onPress={() => onSelect(active ? null : column.selection)}
              accessibilityRole="button"
              accessibilityLabel={column.accessibilityLabel}
              accessibilityHint="Filtre le stock sur ce jour"
              accessibilityState={{ selected: active }}
              style={({ pressed }) => [
                styles.column,
                (active || pressed) && styles.columnActive,
              ]}
            >
              <Text
                style={[styles.count, 0 === column.count && styles.countEmpty]}
                numberOfLines={1}
              >
                {0 === column.count ? '·' : column.count}
              </Text>

              <View style={styles.plot}>
                <View style={[styles.bar, { height, backgroundColor: palette.line }]} />
              </View>

              <View style={styles.baseline} />

              <Text
                style={[styles.axis, active && styles.axisActive]}
                numberOfLines={1}
                adjustsFontSizeToFit
              >
                {column.label}
              </Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.md,
    paddingBottom: spacing.sm,
    marginBottom: spacing.md,
  },
  head: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.sm,
    paddingHorizontal: spacing.xs,
  },
  reset: { paddingVertical: 2, paddingHorizontal: spacing.xs, borderRadius: radius.sm },
  resetPressed: { backgroundColor: colors.surfaceSunk },
  resetLabel: { color: colors.ink },

  strip: { flexDirection: 'row', alignItems: 'flex-end', gap: 2 },
  column: { flex: 1, alignItems: 'center', borderRadius: radius.sm, paddingTop: 2, paddingBottom: 4 },
  // La piste creuse du thème : « champs de saisie, pistes de sélection ».
  columnActive: { backgroundColor: colors.surfaceSunk },

  count: { fontFamily: font.monoMedium, fontSize: 10, lineHeight: 14, color: colors.ink },
  countEmpty: { color: colors.borderStrong },

  /** Les barres partent toutes de la même ligne : c'est ce qui les rend comparables. */
  plot: { height: MAX_BAR, justifyContent: 'flex-end', alignSelf: 'stretch', alignItems: 'center' },
  bar: { width: '58%', maxWidth: 20, borderRadius: 1 },

  baseline: { height: 1, alignSelf: 'stretch', backgroundColor: colors.border, marginTop: 3 },

  axis: {
    fontFamily: font.monoMedium,
    fontSize: 9,
    lineHeight: 13,
    letterSpacing: 0.4,
    color: colors.inkMuted,
    marginTop: 4,
  },
  axisActive: { color: colors.ink },
});
