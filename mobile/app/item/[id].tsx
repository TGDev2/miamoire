import DateTimePicker from '@react-native-community/datetimepicker';
import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { api } from '../../src/api/client';
import type { Reference, StockItem, StockMovement } from '../../src/api/types';
import {
  Badge,
  Button,
  Card,
  Chip,
  ErrorBanner,
  Field,
  Loader,
  SectionTitle,
} from '../../src/components/ui';
import { colors, radius, spacing, statusPresentation } from '../../src/theme';

export default function StockItemScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();

  const [item, setItem] = useState<StockItem | null>(null);
  const [movements, setMovements] = useState<StockMovement[]>([]);
  const [reference, setReference] = useState<Reference | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [amount, setAmount] = useState('');
  const [editing, setEditing] = useState(false);
  const [editName, setEditName] = useState('');
  const [showPicker, setShowPicker] = useState(false);

  const load = useCallback(async () => {
    try {
      const [detail, history, ref] = await Promise.all([
        api.stockItem(id),
        api.movements(id),
        api.reference(),
      ]);
      setItem(detail);
      setEditName(detail.product.name);
      setMovements(history.movements);
      setReference(ref);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    }
  }, [id]);

  useEffect(() => {
    load();
  }, [load]);

  /** Enveloppe commune : exécute l'action, rafraîchit, remonte l'erreur métier. */
  async function run(action: () => Promise<unknown>) {
    setError(null);
    setBusy(true);
    try {
      await action();
      await load();
      setAmount('');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  }

  function parsedAmount(): number | null {
    const value = Number(amount.replace(',', '.'));
    return Number.isFinite(value) && value > 0 ? value : null;
  }

  function confirmDelete() {
    Alert.alert(
      'Supprimer cet aliment ?',
      'La ligne et tout son historique seront définitivement effacés.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Supprimer',
          style: 'destructive',
          onPress: () =>
            run(async () => {
              await api.deleteStockItem(id);
              router.back();
            }),
        },
      ],
    );
  }

  if (!item) {
    return error ? (
      <View style={styles.container}>
        <ErrorBanner message={error} />
      </View>
    ) : (
      <Loader />
    );
  }

  const presentation = statusPresentation[item.status] ?? statusPresentation.sealed;

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      <Card>
        <View style={styles.headerRow}>
          <View style={styles.flex}>
            <Text style={styles.name}>{item.product.name}</Text>
            {item.product.brand ? <Text style={styles.brand}>{item.product.brand}</Text> : null}
          </View>
          <Badge
            label={presentation.label}
            color={presentation.color}
            background={presentation.background}
          />
        </View>

        <Text style={styles.quantity}>
          {item.displayQuantity} {item.displayUnit} disponibles
        </Text>
        <Text style={styles.meta}>{item.storageLocationLabel}</Text>
        <Text style={styles.meta}>
          {item.expirationDate
            ? `À consommer avant le ${formatDate(item.expirationDate)}`
            : 'Aucune date limite renseignée'}
        </Text>
        {item.addedBy ? (
          <Text style={styles.meta}>Enregistré par {item.addedBy}</Text>
        ) : null}
        {item.isOpened ? <Text style={styles.meta}>Entamé</Text> : null}
        {item.quantityIsEstimated ? (
          <Text style={styles.meta}>Quantité estimée, pas mesurée</Text>
        ) : null}
        {item.note ? <Text style={styles.note}>{item.note}</Text> : null}
      </Card>

      <SectionTitle>Consommer</SectionTitle>

      {item.isDiscreteUnit ? (
        <View style={styles.stepper}>
          <Pressable
            style={styles.stepperButton}
            onPress={() => run(() => api.consume(id, { mode: 'units', delta: -1 }))}
            disabled={busy}
          >
            <Text style={styles.stepperGlyph}>−1</Text>
          </Pressable>
          <Text style={styles.stepperValue}>
            {item.remainingQuantity} {item.unitLabel}
          </Text>
          <Pressable
            style={styles.stepperButton}
            onPress={() => run(() => api.addQuantity(id, { quantity: 1 }))}
            disabled={busy}
          >
            <Text style={styles.stepperGlyph}>+1</Text>
          </Pressable>
        </View>
      ) : null}

      <View style={styles.amountRow}>
        <TextInput
          value={amount}
          onChangeText={setAmount}
          keyboardType="decimal-pad"
          placeholder={`Quantité en ${item.unitLabel}`}
          placeholderTextColor={colors.inkMuted}
          style={styles.amountInput}
        />
        <Button
          label="Consommer"
          onPress={() => {
            const value = parsedAmount();
            if (value === null) {
              setError('Indiquez une quantité valide.');
              return;
            }
            run(() => api.consume(id, { mode: 'quantity', quantity: value }));
          }}
          disabled={busy}
          style={styles.amountButton}
        />
      </View>

      <View style={styles.actionGrid}>
        <Button
          label="Tout consommer"
          variant="secondary"
          onPress={() => run(() => api.consume(id, { mode: 'all' }))}
          disabled={busy}
          style={styles.gridItem}
        />
        <Button
          label="Retirer un exemplaire"
          variant="secondary"
          onPress={() => run(() => api.consume(id, { mode: 'package' }))}
          disabled={busy}
          style={styles.gridItem}
        />
        <Button
          label={item.isOpened ? 'Marquer comme fermé' : 'Marquer comme entamé'}
          variant="secondary"
          onPress={() => run(() => api.setOpened(id, !item.isOpened))}
          disabled={busy}
          style={styles.gridItem}
        />
        <Button
          label="Ajouter une quantité"
          variant="secondary"
          onPress={() => {
            const value = parsedAmount();
            if (value === null) {
              setError('Saisissez d\'abord une quantité dans le champ ci-dessus.');
              return;
            }
            run(() => api.addQuantity(id, { quantity: value }));
          }}
          disabled={busy}
          style={styles.gridItem}
        />
      </View>

      <SectionTitle>Corriger la quantité</SectionTitle>
      <Text style={styles.help}>
        Vous ne savez pas exactement ce qu'il reste ? Choisissez une estimation.
      </Text>
      <View style={styles.chips}>
        {reference?.fillLevels.map((level) => (
          <Chip
            key={level.value}
            label={level.label}
            selected={false}
            onPress={() => run(() => api.correct(id, { fillLevel: level.value }))}
          />
        ))}
      </View>
      <Button
        label="Corriger avec la quantité saisie"
        variant="secondary"
        onPress={() => {
          const value = parsedAmount();
          if (value === null) {
            setError('Saisissez la quantité exacte dans le champ « Consommer » ci-dessus.');
            return;
          }
          run(() => api.correct(id, { quantity: value }));
        }}
        disabled={busy}
      />

      <SectionTitle>Modifier</SectionTitle>
      {editing ? (
        <Card>
          <Field label="Nom" value={editName} onChangeText={setEditName} />

          <Text style={styles.label}>Emplacement</Text>
          <View style={styles.chips}>
            {reference?.storageLocations.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={item.storageLocation === option.value}
                onPress={() =>
                  run(() => api.updateStockItem(id, { storageLocation: option.value }))
                }
              />
            ))}
          </View>

          <Text style={styles.label}>Date de péremption</Text>
          <View style={styles.dateRow}>
            <Pressable style={styles.dateButton} onPress={() => setShowPicker(true)}>
              <Text style={styles.dateText}>
                {item.expirationDate ? formatDate(item.expirationDate) : 'Aucune date'}
              </Text>
            </Pressable>
            {item.expirationDate ? (
              <Pressable
                onPress={() => run(() => api.updateStockItem(id, { expirationDate: null }))}
              >
                <Text style={styles.clearText}>Effacer</Text>
              </Pressable>
            ) : null}
          </View>

          {showPicker ? (
            <DateTimePicker
              value={item.expirationDate ? new Date(item.expirationDate) : new Date()}
              mode="date"
              onChange={(event, selected) => {
                setShowPicker(false);
                if (event.type === 'set' && selected) {
                  run(() =>
                    api.updateStockItem(id, { expirationDate: toIsoDate(selected) }),
                  );
                }
              }}
            />
          ) : null}

          <Button
            label="Enregistrer le nom"
            onPress={() => run(() => api.updateStockItem(id, { name: editName }))}
            disabled={busy}
          />
          <Button
            label="Fermer"
            variant="ghost"
            onPress={() => setEditing(false)}
            style={styles.spaced}
          />
        </Card>
      ) : (
        <Button label="Modifier cet aliment" variant="secondary" onPress={() => setEditing(true)} />
      )}

      <SectionTitle>Retirer</SectionTitle>
      <Button
        label="Jeter le produit"
        variant="danger"
        onPress={() => run(() => api.discard(id, 'Produit jeté'))}
        disabled={busy || item.remainingQuantity <= 0}
      />
      <Button
        label="Supprimer la ligne"
        variant="ghost"
        onPress={confirmDelete}
        style={styles.spaced}
      />

      <SectionTitle>Historique</SectionTitle>
      {movements.map((movement) => (
        <View key={movement.id} style={styles.movement}>
          <View style={styles.flex}>
            <Text style={styles.movementLabel}>{movement.typeLabel}</Text>
            {movement.reason ? (
              <Text style={styles.movementReason}>{movement.reason}</Text>
            ) : null}
            <Text style={styles.movementDate}>{formatDateTime(movement.createdAt)}</Text>
          </View>
          <View style={styles.movementRight}>
            <Text
              style={[
                styles.movementQuantity,
                { color: movement.quantity < 0 ? colors.danger : colors.primary },
              ]}
            >
              {movement.quantity > 0 ? '+' : ''}
              {formatNumber(movement.quantity)} {movement.unit}
            </Text>
            <Text style={styles.movementAfter}>
              reste {formatNumber(movement.remainingAfter)} {movement.unit}
            </Text>
          </View>
        </View>
      ))}
    </ScrollView>
  );
}

function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : String(Math.round(value * 100) / 100);
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  headerRow: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  name: { fontSize: 22, fontWeight: '800', color: colors.ink },
  brand: { fontSize: 15, color: colors.inkMuted, marginTop: 2 },
  quantity: { fontSize: 26, fontWeight: '700', color: colors.primary, marginTop: spacing.lg },
  meta: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.xs },
  note: { fontSize: 14, color: colors.ink, marginTop: spacing.md, fontStyle: 'italic' },
  help: { fontSize: 13, color: colors.inkMuted, marginBottom: spacing.md, lineHeight: 19 },
  label: { fontSize: 13, fontWeight: '600', color: colors.inkMuted, marginBottom: spacing.sm },
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.lg,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  stepperButton: {
    width: 56,
    height: 44,
    borderRadius: radius.md,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepperGlyph: { fontSize: 18, fontWeight: '700', color: colors.primary },
  stepperValue: { fontSize: 18, fontWeight: '700', color: colors.ink },
  amountRow: { flexDirection: 'row', gap: spacing.md, alignItems: 'center' },
  amountInput: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 13,
    fontSize: 16,
    color: colors.ink,
  },
  amountButton: { paddingHorizontal: spacing.lg },
  actionGrid: { marginTop: spacing.md, gap: spacing.sm },
  gridItem: {},
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  spaced: { marginTop: spacing.sm },
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.lg },
  dateButton: {
    flex: 1,
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
  },
  dateText: { fontSize: 15, color: colors.ink },
  clearText: { color: colors.danger, fontSize: 14, fontWeight: '600' },
  movement: {
    flexDirection: 'row',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.sm,
    gap: spacing.md,
  },
  movementLabel: { fontSize: 15, fontWeight: '600', color: colors.ink },
  movementReason: { fontSize: 13, color: colors.inkMuted, marginTop: 1 },
  movementDate: { fontSize: 12, color: colors.inkMuted, marginTop: 2 },
  movementRight: { alignItems: 'flex-end' },
  movementQuantity: { fontSize: 15, fontWeight: '700' },
  movementAfter: { fontSize: 12, color: colors.inkMuted, marginTop: 2 },
});
