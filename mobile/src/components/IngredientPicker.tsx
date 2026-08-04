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

import type { InventoryIngredient, Priority } from '../api/types';
import { colors, font, radius, spacing, text, thermal } from '../theme';
import { Icon } from './ui';

/**
 * Choisir jusqu'à `max` aliments parmi tout l'inventaire, sans dérouler tout
 * l'inventaire — et, pour chacun, dire facultativement combien en écouler.
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
 *
 * La quantité obéit à la même règle que l'aliment lui-même : elle n'apparaît
 * qu'une fois l'aliment choisi, et laissée vide elle ne contraint rien. Qui ne
 * veut pas de quantité ne doit pas avoir un geste de plus à faire.
 */
export function IngredientPicker({
  ingredients,
  value,
  onChange,
  max = 3,
}: {
  ingredients: InventoryIngredient[];
  value: Priority[];
  onChange: (priorities: Priority[]) => void;
  max?: number;
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');

  /**
   * Ce qui est tapé, tel qu'il est tapé.
   *
   * Sans lui, effacer le « 0 » de « 40 » relit le nombre 4 et le réécrit dans
   * le champ : impossible d'atteindre 400 en corrigeant. Le brouillon garde la
   * frappe, la valeur n'en retient que ce qui est un nombre.
   */
  const [drafts, setDrafts] = useState<Record<string, string>>({});

  const byKey = new Map(ingredients.map((entry) => [entry.key, entry]));
  const selected = value
    .map((priority) => ({ priority, ingredient: byKey.get(priority.key) }))
    .filter((entry): entry is { priority: Priority; ingredient: InventoryIngredient } =>
      Boolean(entry.ingredient),
    );
  const atLimit = value.length >= max;

  /**
   * Trois groupes, dans l'ordre où ils se perdent — le même que celui qui classe
   * l'inventaire envoyé au modèle, pour que l'écran et la génération ne puissent
   * pas se contredire :
   *
   * 1. ce qui périme sous trois jours, ou ce qui est déjà entamé ;
   * 2. les produits frais, qui se dégradent bien avant leur date là où une
   *    conserve attend deux ans ;
   * 3. le reste.
   *
   * La recherche, elle, porte sur tout — chercher « poulet » ne doit pas dépendre
   * de sa fraîcheur.
   */
  const sections = useMemo(() => {
    const needle = search.trim().toLowerCase();
    const matching = needle
      ? ingredients.filter((entry) => entry.label.toLowerCase().includes(needle))
      : ingredients;

    const urgent = matching.filter((entry) => entry.expiresSoon || entry.alreadyOpened);
    const fresh = matching.filter(
      (entry) => !entry.expiresSoon && !entry.alreadyOpened && entry.perishable,
    );
    const rest = matching.filter(
      (entry) => !entry.expiresSoon && !entry.alreadyOpened && !entry.perishable,
    );

    return [
      { title: 'À écouler en priorité', data: urgent },
      { title: 'Produits frais', data: fresh },
      { title: 'Tout l\'inventaire', data: rest },
    ].filter((section) => section.data.length > 0);
  }, [ingredients, search]);

  function toggle(key: string) {
    if (value.some((entry) => entry.key === key)) {
      onChange(value.filter((entry) => entry.key !== key));
      // Désélectionner efface la quantité : la resélectionner plus tard ne doit
      // pas ressusciter un chiffre que l'utilisateur croyait avoir retiré.
      setDrafts((current) => {
        const { [key]: _removed, ...rest } = current;
        return rest;
      });
      return;
    }

    if (value.length >= max) return;
    onChange([...value, { key }]);
  }

  function update(key: string, next: Priority) {
    onChange(value.map((entry) => (entry.key === key ? next : entry)));
  }

  function clear() {
    onChange([]);
    setDrafts({});
  }

  return (
    <>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={
          selected.length > 0
            ? `Aliments imposés : ${selected.map(describeSelection).join(' ; ')}`
            : 'Aucun aliment imposé'
        }
        accessibilityHint="Ouvre la liste des aliments de votre stock"
        style={({ pressed }) => [styles.trigger, pressed && styles.pressed]}
      >
        <View style={styles.flex}>
          {selected.length > 0 ? (
            <>
              <Text style={styles.triggerValue} numberOfLines={2}>
                {selected.map(describeSelection).join(' · ')}
              </Text>
              <Text style={styles.triggerHint}>
                {selected.length > 1
                  ? 'Les trois recettes les utiliseront. Touchez pour changer.'
                  : "Les trois recettes l'utiliseront. Touchez pour changer."}
              </Text>
            </>
          ) : (
            <>
              <Text style={styles.triggerEmpty}>Aucun aliment imposé</Text>
              <Text style={styles.triggerHint}>
                Facultatif. Touchez pour en choisir jusqu'à {max} à écouler.
              </Text>
            </>
          )}
        </View>

        {/* Retirer sa sélection sans rouvrir la liste : c'est le geste le plus
            fréquent après l'avoir posée. */}
        {selected.length > 0 ? (
          <Pressable
            onPress={clear}
            hitSlop={12}
            accessibilityRole="button"
            accessibilityLabel="Retirer les aliments imposés"
            style={styles.clear}
          >
            <Text style={styles.clearLabel}>Retirer</Text>
          </Pressable>
        ) : (
          <Icon name="chevron-right" size={20} color={colors.border} />
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
              Aliments à utiliser
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
              value.length > 0 ? (
                <Pressable
                  onPress={clear}
                  accessibilityRole="button"
                  accessibilityLabel="Vider la sélection"
                  style={({ pressed }) => [styles.rowMain, pressed && styles.pressed]}
                >
                  <Text style={styles.rowLabel}>Vider la sélection</Text>
                </Pressable>
              ) : null
            }
            renderSectionHeader={({ section }) => (
              <Text style={styles.sectionHeader}>{section.title}</Text>
            )}
            renderItem={({ item }) => {
              const meta = describeDeadlines(item);
              const priority = value.find((entry) => entry.key === item.key);
              const disabled = !priority && atLimit;

              return (
                <View style={[styles.row, disabled && styles.rowDisabled]}>
                  <Pressable
                    onPress={() => toggle(item.key)}
                    disabled={disabled}
                    accessibilityRole="button"
                    accessibilityLabel={
                      disabled ? `${item.label}, ${meta}, maximum ${max} aliments` : `${item.label}, ${meta}`
                    }
                    // La sélection n'était marquée que par un « ✓ » décoratif :
                    // invisible pour un lecteur d'écran, qui lisait donc deux
                    // lignes identiques sans savoir laquelle était choisie.
                    accessibilityState={{ selected: Boolean(priority), disabled }}
                    style={({ pressed }) => [styles.rowMain, pressed && !disabled && styles.pressed]}
                  >
                    <View style={styles.flex}>
                      <Text style={[styles.rowLabel, priority && styles.rowLabelSelected]}>
                        {item.label}
                      </Text>
                      <Text style={styles.rowMeta}>{meta}</Text>
                    </View>
                    {priority ? <Icon name="check" size={18} color={colors.ink} /> : null}
                  </Pressable>

                  {/* Hors du `Pressable` : taper dans le champ ne doit pas
                      désélectionner l'aliment qu'on est en train de régler. */}
                  {priority ? (
                    <AmountEditor
                      ingredient={item}
                      priority={priority}
                      draft={drafts[item.key] ?? ''}
                      onDraft={(next) => setDrafts((current) => ({ ...current, [item.key]: next }))}
                      onChange={(next) => update(item.key, next)}
                    />
                  ) : null}
                </View>
              );
            }}
            ListEmptyComponent={
              <Text style={styles.empty}>
                Aucun aliment ne correspond à « {search.trim()} ».
              </Text>
            }
            ListFooterComponent={
              atLimit ? (
                <Text style={styles.limitNote}>
                  Maximum {max} aliments : retirez-en un pour en choisir un autre.
                </Text>
              ) : null
            }
          />
        </SafeAreaView>
      </Modal>
    </>
  );
}

/**
 * La quantité à écouler d'un aliment déjà choisi — toujours facultative.
 *
 * Deux unités possibles quand l'aliment est conditionné : la sienne (400 g) ou
 * l'exemplaire (1 boîte). Le second n'est proposé que si le serveur a su dire
 * ce que pèse un exemplaire ; sinon le choix n'aurait aucun sens, et une
 * bascule sans effet vaut moins que pas de bascule du tout.
 */
function AmountEditor({
  ingredient,
  priority,
  draft,
  onDraft,
  onChange,
}: {
  ingredient: InventoryIngredient;
  priority: Priority;
  draft: string;
  onDraft: (value: string) => void;
  onChange: (priority: Priority) => void;
}) {
  const packaging = ingredient.packaging;
  const byPackage = priority.packages !== undefined;

  function apply(raw: string, packages: boolean) {
    onDraft(raw);

    const parsed = Number(raw.replace(',', '.'));

    // Vide, ou pas encore un nombre : aucune contrainte de quantité. C'est
    // l'état par défaut, et il doit rester atteignable en effaçant le champ.
    if ('' === raw.trim() || !Number.isFinite(parsed) || parsed <= 0) {
      onChange({ key: priority.key });
      return;
    }

    onChange(
      packages
        ? { key: priority.key, packages: Math.max(1, Math.round(parsed)) }
        : { key: priority.key, quantity: parsed, unit: ingredient.unit },
    );
  }

  return (
    <View style={styles.amount}>
      {/* Sur sa propre ligne : côté à côte avec le champ et les deux unités,
          « À écouler » se faisait écraser jusqu'à n'afficher qu'un « À »
          orphelin — une largeur qu'aucune assertion ne regardait. */}
      <Text style={styles.amountLabel}>À écouler — facultatif</Text>

      <View style={styles.amountControls}>
        <TextInput
          value={draft}
          onChangeText={(raw) => apply(raw, byPackage)}
          placeholder="—"
          placeholderTextColor={colors.inkMuted}
          keyboardType={byPackage ? 'number-pad' : 'decimal-pad'}
          accessibilityLabel={`Quantité de ${ingredient.label} à écouler, facultative`}
          style={styles.amountField}
        />

        {packaging ? (
          <View style={styles.units} accessibilityRole="radiogroup">
            <UnitChip
              label={ingredient.unitLabel}
              selected={!byPackage}
              onPress={() => apply(draft, false)}
            />
            <UnitChip
              label={packaging.count > 1 ? 'exemplaires' : 'exemplaire'}
              selected={byPackage}
              onPress={() => apply(draft, true)}
            />
          </View>
        ) : (
          <Text style={styles.amountUnit}>{ingredient.unitLabel}</Text>
        )}
      </View>
    </View>
  );
}

function UnitChip({
  label,
  selected,
  onPress,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="radio"
      accessibilityState={{ selected }}
      accessibilityLabel={label}
      style={({ pressed }) => [styles.unit, selected && styles.unitSelected, pressed && styles.pressed]}
    >
      <Text style={[styles.unitLabel, selected && styles.unitLabelSelected]}>{label}</Text>
    </Pressable>
  );
}

/** « Tomates pelées (1 boîte) » — l'aliment, et ce qu'on en veut quand on l'a dit. */
function describeSelection({
  priority,
  ingredient,
}: {
  priority: Priority;
  ingredient: InventoryIngredient;
}): string {
  if (priority.packages !== undefined) {
    return `${ingredient.label} (${priority.packages} ${priority.packages > 1 ? 'exemplaires' : 'exemplaire'})`;
  }

  if (priority.quantity !== undefined) {
    return `${ingredient.label} (${formatQuantity(priority.quantity)} ${ingredient.unitLabel})`;
  }

  return ingredient.label;
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
    // Le conditionnement ne s'affiche que lorsqu'il ajoute quelque chose au
    // total : dire « 400 g · 1 exemplaire » n'apprend rien.
    item.packaging && item.packaging.count > 1
      ? `${item.packaging.count} x ${formatQuantity(item.packaging.size)} ${item.packaging.unitLabel}`
      : null,
    item.expiresSoon ? 'périme bientôt' : null,
    // « paquet entamé » ne veut rien dire d'un produit frais : il n'a pas d'emballage.
    item.alreadyOpened && !item.perishable ? 'paquet entamé' : null,
    item.perishable && !item.expiresSoon ? 'produit frais' : null,
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
  pressed: { backgroundColor: colors.surfaceSunk },
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
  triggerValue: text.subheading,
  triggerEmpty: { ...text.body, color: colors.inkMuted },
  triggerHint: { ...text.small, marginTop: 2 },
  clear: { paddingHorizontal: spacing.sm, paddingVertical: spacing.xs },
  clearLabel: { ...text.small, color: thermal.critical.line, fontFamily: font.sansMedium },
  sheet: { flex: 1, backgroundColor: colors.ground },
  sheetHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.xl,
    paddingBottom: spacing.md,
  },
  sheetTitle: text.title,
  close: { ...text.button, color: colors.ink },
  search: {
    marginHorizontal: spacing.xl,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    minHeight: 48,
    ...text.body,
  },
  listContent: { padding: spacing.xl, paddingBottom: spacing.xxl },
  sectionHeader: { ...text.label, marginTop: spacing.xl, marginBottom: spacing.sm },
  row: { borderBottomWidth: 1, borderBottomColor: colors.border },
  rowMain: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
    minHeight: 56,
  },
  rowDisabled: { opacity: 0.4 },
  rowLabel: text.body,
  rowLabelSelected: { fontFamily: font.sansSemiBold },
  rowMeta: { ...text.data, marginTop: 2 },
  amount: { gap: spacing.xs, paddingBottom: spacing.md },
  amountControls: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  amountLabel: { ...text.label },
  amountField: {
    minWidth: 72,
    minHeight: 40,
    textAlign: 'center',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.sm,
    ...text.body,
  },
  amountUnit: { ...text.small, color: colors.inkMuted },
  units: { flexDirection: 'row', gap: spacing.xs },
  unit: {
    paddingHorizontal: spacing.sm,
    paddingVertical: spacing.xs,
    minHeight: 40,
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
  },
  unitSelected: { backgroundColor: colors.ink, borderColor: colors.ink },
  unitLabel: { ...text.small, color: colors.inkMuted },
  unitLabelSelected: { color: colors.onInk, fontFamily: font.sansMedium },
  empty: { ...text.small, marginTop: spacing.xl, textAlign: 'center' },
  limitNote: { ...text.small, textAlign: 'center', marginTop: spacing.lg },
});
