import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../src/api/client';
import type { Reference, ShoppingListItem, Unit } from '../src/api/types';
import {
  Button,
  Chip,
  EmptyState,
  ErrorBanner,
  Field,
  Icon,
  IconButton,
  Loader,
  SectionTitle,
} from '../src/components/ui';
import { useShoppingSession } from '../src/shopping/ShoppingSessionContext';
import { colors, font, spacing, text } from '../src/theme';

/**
 * La liste de courses du foyer : pas un container séparé, une seule liste par
 * foyer — comme il a un seul stock. Alimentée depuis « À compléter » d'une
 * recette (voir `RecipeDetailView`) ou saisie ici à la main.
 */
export default function ShoppingListScreen() {
  const router = useRouter();
  const { addListLines } = useShoppingSession();
  const [reference, setReference] = useState<Reference | null>(null);
  const [items, setItems] = useState<ShoppingListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const [label, setLabel] = useState('');
  const [quantity, setQuantity] = useState('');
  const [unit, setUnit] = useState<Unit>('piece');
  const [adding, setAdding] = useState(false);

  const load = useCallback(async () => {
    try {
      const response = await api.shoppingList();
      setItems(response.items);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  useEffect(() => {
    api
      .reference()
      .then(setReference)
      .catch(() => undefined);
  }, []);

  async function addManually() {
    const trimmed = label.trim();
    if ('' === trimmed) {
      setError('Indiquez au moins un nom d\'aliment.');
      return;
    }

    setError(null);
    setAdding(true);
    try {
      const parsed = quantity.trim() === '' ? undefined : Number(quantity.replace(',', '.'));
      await api.addShoppingListItems([
        { label: trimmed, quantity: Number.isFinite(parsed) ? parsed : undefined, unit },
      ]);
      setLabel('');
      setQuantity('');
      await load();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Ajout impossible.');
    } finally {
      setAdding(false);
    }
  }

  async function toggle(item: ShoppingListItem) {
    setError(null);
    setBusyId(item.id);
    try {
      await api.toggleShoppingListItem(item.id, !item.checked);
      await load();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Action impossible.');
    } finally {
      setBusyId(null);
    }
  }

  async function remove(item: ShoppingListItem) {
    setError(null);
    setBusyId(item.id);
    try {
      await api.deleteShoppingListItem(item.id);
      await load();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Retrait impossible.');
    } finally {
      setBusyId(null);
    }
  }

  if (null === items) {
    return <Loader label="Chargement de la liste" />;
  }

  const toBuy = items.filter((item) => !item.checked);
  const bought = items.filter((item) => item.checked);

  /** What was ticked in the store is what came home: it goes to the return, where the server fills the rest. */
  function addBoughtToReturn() {
    addListLines(bought);
    router.push('/shopping-review');
  }

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Button
        label="Vérifier avant d'acheter"
        variant="secondary"
        icon="cart-outline"
        onPress={() => router.push('/shopping-advisor')}
        hint="Ce que le stock actuel, l'historique et les repas prévus suggèrent de ne pas racheter"
        style={styles.advisorButton}
      />

      {/* La liste avant le formulaire : on ouvre cet écran dans le magasin pour
          cocher, bien plus souvent que chez soi pour ajouter. */}
      {0 === items.length ? (
        <EmptyState
          icon="format-list-checks"
          title="Liste vide"
          description="Ajoutez un aliment ci-dessous, ou depuis les ingrédients manquants d'une recette."
        />
      ) : (
        <>
          <SectionTitle>{`À acheter · ${toBuy.length}`}</SectionTitle>
          {0 === toBuy.length ? (
            <Text style={text.small}>Tout est coché — bonnes courses.</Text>
          ) : (
            toBuy.map((item) => (
              <ListRow key={item.id} item={item} busy={busyId === item.id} onToggle={toggle} onRemove={remove} />
            ))
          )}

          {bought.length > 0 ? (
            <>
              <SectionTitle>{`Déjà pris · ${bought.length}`}</SectionTitle>
              {bought.map((item) => (
                <ListRow key={item.id} item={item} busy={busyId === item.id} onToggle={toggle} onRemove={remove} />
              ))}
              <Button
                label={`Ajouter au retour (${bought.length})`}
                variant="secondary"
                icon="basket-outline"
                onPress={addBoughtToReturn}
                hint="Ouvre le retour de courses avec ces lignes déjà remplies"
                style={styles.returnButton}
              />
            </>
          ) : null}
        </>
      )}

      <SectionTitle>Ajouter un aliment</SectionTitle>
      <Field
        label="Aliment"
        hideLabel
        value={label}
        onChangeText={setLabel}
        placeholder="Pain, œufs, farine…"
      />
      <Field
        label="Quantité (facultatif)"
        value={quantity}
        onChangeText={setQuantity}
        keyboardType="decimal-pad"
        placeholder="ex. 500"
      />
      <Text style={styles.unitLabel}>Unité</Text>
      <View style={styles.chips}>
        {(reference?.units ?? []).map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={unit === option.value}
            onPress={() => setUnit(option.value as Unit)}
          />
        ))}
      </View>
      <Button label="Ajouter à la liste" onPress={addManually} loading={adding} style={styles.addButton} />
    </ScrollView>
  );
}

function ListRow({
  item,
  busy,
  onToggle,
  onRemove,
}: {
  item: ShoppingListItem;
  busy: boolean;
  onToggle: (item: ShoppingListItem) => void;
  onRemove: (item: ShoppingListItem) => void;
}) {
  return (
    <View style={styles.row}>
      <Pressable
        style={styles.rowMain}
        onPress={() => onToggle(item)}
        disabled={busy}
        accessibilityRole="checkbox"
        accessibilityState={{ checked: item.checked, disabled: busy }}
        accessibilityLabel={item.label}
      >
        <View style={[styles.checkbox, item.checked && styles.checkboxChecked]}>
          {item.checked ? <Icon name="check" size={14} color={colors.onInk} /> : null}
        </View>
        <View style={styles.flex}>
          <Text style={[styles.label, item.checked && styles.labelChecked]}>{item.label}</Text>
          {null !== item.quantity ? (
            <Text style={styles.quantity}>
              {item.quantity} {item.unitLabel}
            </Text>
          ) : null}
        </View>
      </Pressable>
      <IconButton
        icon="close"
        label={`Retirer ${item.label} de la liste`}
        tone="critical"
        disabled={busy}
        onPress={() => onRemove(item)}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  advisorButton: { marginBottom: spacing.sm },
  returnButton: { marginTop: spacing.md },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  unitLabel: { ...text.label, marginBottom: spacing.sm },
  addButton: { marginTop: spacing.sm, marginBottom: spacing.lg },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.sm,
    minHeight: 52,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    gap: spacing.sm,
  },
  rowMain: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: { backgroundColor: colors.ink, borderColor: colors.ink },
  label: text.body,
  labelChecked: { color: colors.inkMuted, textDecorationLine: 'line-through' },
  quantity: { fontFamily: font.mono, fontSize: 11, lineHeight: 16, color: colors.inkMuted, marginTop: 2 },
});
