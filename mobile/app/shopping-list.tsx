import { useFocusEffect } from 'expo-router';
import React, { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../src/api/client';
import type { Reference, ShoppingListItem, Unit } from '../src/api/types';
import { Button, Chip, EmptyState, ErrorBanner, Field, Loader, SectionTitle } from '../src/components/ui';
import { colors, spacing } from '../src/theme';

/**
 * La liste de courses du foyer : pas un container séparé, une seule liste par
 * foyer — comme il a un seul stock. Alimentée depuis « À compléter » d'une
 * recette (voir `RecipeDetailView`) ou saisie ici à la main.
 */
export default function ShoppingListScreen() {
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
    return <Loader label="Chargement de la liste…" />;
  }

  const toBuy = items.filter((item) => !item.checked);
  const bought = items.filter((item) => item.checked);

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <SectionTitle>Ajouter</SectionTitle>
      <Field label="Aliment" value={label} onChangeText={setLabel} placeholder="Pain, œufs, farine…" />
      <Field
        label="Quantité (facultatif)"
        value={quantity}
        onChangeText={setQuantity}
        keyboardType="decimal-pad"
        placeholder="ex. 500"
      />
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

      {0 === items.length ? (
        <EmptyState
          title="Liste vide"
          description="Ajoutez un aliment ci-dessus, ou depuis les ingrédients manquants d'une recette."
        />
      ) : (
        <>
          <SectionTitle>À acheter</SectionTitle>
          {0 === toBuy.length ? (
            <Text style={styles.help}>Tout est coché — bonnes courses !</Text>
          ) : (
            toBuy.map((item) => (
              <Row key={item.id} item={item} busy={busyId === item.id} onToggle={toggle} onRemove={remove} />
            ))
          )}

          {bought.length > 0 ? (
            <>
              <SectionTitle>Déjà pris</SectionTitle>
              {bought.map((item) => (
                <Row key={item.id} item={item} busy={busyId === item.id} onToggle={toggle} onRemove={remove} />
              ))}
            </>
          ) : null}
        </>
      )}
    </ScrollView>
  );
}

function Row({
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
        <Text style={styles.checkbox}>{item.checked ? '☑' : '☐'}</Text>
        <View style={styles.flex}>
          <Text style={[styles.label, item.checked && styles.labelChecked]}>{item.label}</Text>
          {null !== item.quantity ? (
            <Text style={styles.quantity}>
              {item.quantity} {item.unitLabel}
            </Text>
          ) : null}
        </View>
      </Pressable>
      <Pressable
        onPress={() => onRemove(item)}
        disabled={busy}
        accessibilityRole="button"
        accessibilityLabel={`Retirer ${item.label} de la liste`}
      >
        <Text style={styles.remove}>Retirer</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  addButton: { marginTop: spacing.sm, marginBottom: spacing.lg },
  help: { fontSize: 14, color: colors.inkMuted },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.sm + 2,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
    gap: spacing.md,
  },
  rowMain: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  checkbox: { fontSize: 20, color: colors.primary },
  label: { fontSize: 15, color: colors.ink },
  labelChecked: { color: colors.inkMuted, textDecorationLine: 'line-through' },
  quantity: { fontSize: 13, color: colors.inkMuted, marginTop: 2 },
  remove: { fontSize: 13, color: colors.danger, fontWeight: '600' },
});
