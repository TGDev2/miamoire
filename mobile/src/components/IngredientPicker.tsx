import React, { useMemo, useState } from 'react';
import {
  Modal,
  Pressable,
  SectionList,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import type { InventoryIngredient } from '../api/types';
import { colors, radius, spacing } from '../theme';

/**
 * Choisir un aliment parmi tout l'inventaire, sans dérouler tout l'inventaire.
 *
 * La première version affichait une puce par ingrédient dans l'écran « Cuisiner ».
 * Sur un vrai foyer — 115 ingrédients, aux noms de fiches Open Food Facts longs
 * comme « Vinaigre d'alcool coloré (6 % d'acidité) 1 litre » — chaque puce
 * occupait toute la largeur : le nuage devenait une liste de 115 lignes, et il
 * fallait **huit balayages** pour atteindre « Proposer 3 recettes ». Un réglage
 * facultatif enterrait l'action principale de l'écran.
 *
 * D'où la forme retenue : une seule ligne repliée, et la sélection dans une
 * feuille dédiée. Trois conséquences qui comptent :
 *
 * - l'écran « Cuisiner » retrouve une hauteur constante, quel que soit le stock ;
 * - la recherche devient possible, ce qu'une liste de puces ne permettait pas ;
 * - ce qu'on a choisi reste visible à côté du bouton, alors qu'il fallait
 *   auparavant remonter huit écrans pour le vérifier.
 */
export function IngredientPicker({
  ingredients,
  selectedKey,
  onSelect,
}: {
  ingredients: InventoryIngredient[];
  selectedKey: string | null;
  onSelect: (key: string | null) => void;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');

  const selected = ingredients.find((entry) => entry.key === selectedKey) ?? null;

  /**
   * Deux groupes, et le premier est la raison d'être de l'écran : on impose
   * presque toujours un aliment parce qu'il faut l'écouler. La recherche, elle,
   * porte sur tout — chercher « poulet » ne doit pas dépendre de sa fraîcheur.
   */
  const sections = useMemo(() => {
    const needle = search.trim().toLowerCase();
    const matching = needle
      ? ingredients.filter((entry) => entry.label.toLowerCase().includes(needle))
      : ingredients;

    const urgent = matching.filter((entry) => entry.expiresSoon || entry.alreadyOpened);
    const rest = matching.filter((entry) => !entry.expiresSoon && !entry.alreadyOpened);

    return [
      { title: 'À écouler en priorité', data: urgent },
      { title: 'Tout l\'inventaire', data: rest },
    ].filter((section) => section.data.length > 0);
  }, [ingredients, search]);

  function choose(key: string | null) {
    onSelect(key);
    setSearch('');
    setOpen(false);
  }

  return (
    <>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={
          selected ? `Aliment imposé : ${selected.label}` : 'Aucun aliment imposé'
        }
        accessibilityHint="Ouvre la liste des aliments de votre stock"
        style={({ pressed }) => [styles.trigger, pressed && styles.pressed]}
      >
        <View style={styles.flex}>
          {selected ? (
            <>
              <Text style={styles.triggerValue} numberOfLines={2}>
                {selected.label}
              </Text>
              <Text style={styles.triggerHint}>
                Les trois recettes l'utiliseront. Touchez pour changer.
              </Text>
            </>
          ) : (
            <>
              <Text style={styles.triggerEmpty}>Aucun aliment imposé</Text>
              <Text style={styles.triggerHint}>
                Facultatif. Touchez pour en choisir un à écouler.
              </Text>
            </>
          )}
        </View>

        {/* Retirer sa sélection sans rouvrir la liste : c'est le geste le plus
            fréquent après l'avoir posée. */}
        {selected ? (
          <Pressable
            onPress={() => choose(null)}
            hitSlop={12}
            accessibilityRole="button"
            accessibilityLabel={`Retirer l'aliment imposé ${selected.label}`}
            style={styles.clear}
          >
            <Text style={styles.clearLabel}>Retirer</Text>
          </Pressable>
        ) : (
          <Text style={styles.chevron} importantForAccessibility="no">
            ›
          </Text>
        )}
      </Pressable>

      <Modal
        visible={open}
        animationType="slide"
        presentationStyle="pageSheet"
        onRequestClose={() => setOpen(false)}
      >
        {/* Un `Modal` sort de l'arbre des marges de sécurité : sans ce
            `SafeAreaView`, le titre passe sous la barre d'état sur Android.
            `accessibilityViewIsModal` fait de même pour le lecteur d'écran, qui
            continuerait sinon à parcourir l'écran resté dessous. */}
        <SafeAreaView style={styles.sheet} accessibilityViewIsModal>
          <View style={styles.sheetHeader}>
            <Text style={styles.sheetTitle} accessibilityRole="header">
              Aliment à utiliser
            </Text>
            <Pressable
              onPress={() => setOpen(false)}
              hitSlop={12}
              accessibilityRole="button"
              accessibilityLabel="Fermer la liste des aliments"
            >
              <Text style={styles.close}>Fermer</Text>
            </Pressable>
          </View>

          <TextInput
            value={search}
            onChangeText={setSearch}
            placeholder="Rechercher un aliment…"
            placeholderTextColor={colors.inkMuted}
            accessibilityLabel="Rechercher un aliment"
            style={styles.search}
            autoCorrect={false}
          />

          <SectionList
            sections={sections}
            keyExtractor={(item) => item.key}
            keyboardShouldPersistTaps="handled"
            contentContainerStyle={styles.listContent}
            ListHeaderComponent={
              <Pressable
                onPress={() => choose(null)}
                accessibilityRole="button"
                accessibilityLabel="Aucun aliment imposé"
                accessibilityState={{ selected: selectedKey === null }}
                style={({ pressed }) => [styles.row, pressed && styles.pressed]}
              >
                <Text style={[styles.rowLabel, selectedKey === null && styles.rowLabelSelected]}>
                  Aucun aliment imposé
                </Text>
                {selectedKey === null ? (
                  <Text style={styles.check} importantForAccessibility="no">
                    ✓
                  </Text>
                ) : null}
              </Pressable>
            }
            renderSectionHeader={({ section }) => (
              <Text style={styles.sectionHeader}>{section.title}</Text>
            )}
            renderItem={({ item }) => {
              const meta = describeDeadlines(item);

              return (
                <Pressable
                  onPress={() => choose(item.key)}
                  accessibilityRole="button"
                  accessibilityLabel={`${item.label}, ${meta}`}
                  // La sélection n'était marquée que par un « ✓ » décoratif :
                  // invisible pour un lecteur d'écran, qui lisait donc deux
                  // lignes identiques sans savoir laquelle était choisie.
                  accessibilityState={{ selected: selectedKey === item.key }}
                  style={({ pressed }) => [styles.row, pressed && styles.pressed]}
                >
                  <View style={styles.flex}>
                    <Text
                      style={[styles.rowLabel, selectedKey === item.key && styles.rowLabelSelected]}
                    >
                      {item.label}
                    </Text>
                    <Text style={styles.rowMeta}>{meta}</Text>
                  </View>
                  {selectedKey === item.key ? (
                    <Text style={styles.check} importantForAccessibility="no">
                      ✓
                    </Text>
                  ) : null}
                </Pressable>
              );
            }}
            ListEmptyComponent={
              <Text style={styles.empty}>
                Aucun aliment ne correspond à « {search.trim()} ».
              </Text>
            }
          />
        </SafeAreaView>
      </Modal>
    </>
  );
}

/**
 * Une seule quantité globale marquée « périme bientôt » mentait dès qu'un
 * ingrédient avait deux lots aux échéances différentes — 250 g à utiliser
 * demain, 1 kg scellé sans urgence, tous deux fondus sous un même badge.
 * Au-delà d'un groupe, on décrit chaque part séparément plutôt que de
 * continuer à mentir sur la part qui n'est pas concernée.
 */
function describeDeadlines(item: InventoryIngredient): string {
  if (item.deadlines.length > 1) {
    return item.deadlines
      .map((bucket) => {
        const quantity = `${formatQuantity(bucket.quantity)} ${item.unitLabel}`;

        if (bucket.opened) return `${quantity} entamés`;
        if (bucket.useBy) return `${quantity} à utiliser avant le ${formatShortDate(bucket.useBy)}`;

        return `${quantity} sans date`;
      })
      .join(' · ');
  }

  return [
    `${item.quantity} ${item.unitLabel}`,
    item.expiresSoon ? 'périme bientôt' : null,
    item.alreadyOpened ? 'paquet entamé' : null,
  ]
    .filter(Boolean)
    .join(' · ');
}

function formatQuantity(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100);
}

function formatShortDate(value: string): string {
  return new Date(value).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.7 },
  trigger: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.lg,
  },
  triggerValue: { fontSize: 16, fontWeight: '700', color: colors.primary },
  triggerEmpty: { fontSize: 16, fontWeight: '600', color: colors.ink },
  triggerHint: { fontSize: 13, color: colors.inkMuted, marginTop: 2, lineHeight: 18 },
  clear: { paddingHorizontal: spacing.sm, paddingVertical: spacing.xs },
  clearLabel: { fontSize: 14, fontWeight: '600', color: colors.danger },
  chevron: { fontSize: 26, color: colors.inkMuted, lineHeight: 28 },
  sheet: { flex: 1, backgroundColor: colors.background },
  sheetHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.xl,
    paddingBottom: spacing.md,
  },
  sheetTitle: { fontSize: 20, fontWeight: '800', color: colors.ink },
  close: { fontSize: 15, fontWeight: '600', color: colors.primary },
  search: {
    marginHorizontal: spacing.xl,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontSize: 16,
    color: colors.ink,
  },
  listContent: { padding: spacing.xl, paddingBottom: spacing.xxl },
  sectionHeader: {
    fontSize: 12,
    fontWeight: '700',
    color: colors.inkMuted,
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    marginTop: spacing.lg,
    marginBottom: spacing.xs,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  rowLabel: { fontSize: 15, color: colors.ink },
  rowLabelSelected: { fontWeight: '700', color: colors.primary },
  rowMeta: { fontSize: 13, color: colors.inkMuted, marginTop: 2 },
  check: { fontSize: 17, fontWeight: '700', color: colors.primary },
  empty: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.xl, textAlign: 'center' },
});
