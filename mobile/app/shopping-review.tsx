import DateTimePicker from '@react-native-community/datetimepicker';
import { useRouter } from 'expo-router';
import React, { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api } from '../src/api/client';
import type { Reference } from '../src/api/types';
import { Badge, Button, Card, Chip, EmptyState, ErrorBanner, Field, Loader } from '../src/components/ui';
import type { ShoppingSessionBatch, ShoppingSessionLine } from '../src/shopping/ShoppingSessionContext';
import { useShoppingSession } from '../src/shopping/ShoppingSessionContext';
import { colors, font, radius, spacing, text, thermal } from '../src/theme';

type LineStatus = 'idle' | 'busy' | 'error';

/** La date en cours d'édition : celle d'une ligne entière, ou celle d'un lot précis. */
interface PickerTarget {
  lineId: string;
  batchId: number | null;
}

let nextBatchId = 1;

/**
 * Validation groupée du panier de « Retour de courses ».
 *
 * Chaque ligne est un achat indépendant, pas un geste atomique unique comme
 * la confirmation d'une recette : un échec n'annule pas les lignes déjà
 * validées, et reste seul à corriger puis revalider.
 */
export default function ShoppingReviewScreen() {
  const router = useRouter();
  const { lines, updateLine, removeLine, clear, operationKeyFor } = useShoppingSession();

  const [reference, setReference] = useState<Reference | null>(null);
  const [statuses, setStatuses] = useState<Record<string, LineStatus>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [picker, setPicker] = useState<PickerTarget | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.reference().then(setReference).catch(() => {});
  }, []);

  if (0 === lines.length) {
    return (
      <View style={styles.container}>
        <EmptyState
          title="Panier vide"
          description="Retournez à l'onglet Scanner pour ajouter des produits."
        />
        <Button label="Retour" variant="secondary" onPress={() => router.back()} style={styles.spaced} />
      </View>
    );
  }

  async function validateAll() {
    setGlobalError(null);
    setBusy(true);

    let anyFailed = false;

    for (const line of lines) {
      const perPackage = Number(line.quantityPerPackage.replace(',', '.'));
      if (!Number.isFinite(perPackage) || perPackage <= 0) {
        setStatuses((current) => ({ ...current, [line.id]: 'error' }));
        setErrors((current) => ({ ...current, [line.id]: 'Quantité par exemplaire invalide.' }));
        anyFailed = true;
        continue;
      }
      if ('' === line.name.trim()) {
        setStatuses((current) => ({ ...current, [line.id]: 'error' }));
        setErrors((current) => ({ ...current, [line.id]: 'Indiquez le nom de l\'aliment.' }));
        anyFailed = true;
        continue;
      }

      setStatuses((current) => ({ ...current, [line.id]: 'busy' }));

      try {
        await api.createStockItem(
          {
            productId: line.productId ?? undefined,
            barcode: line.productId ? undefined : line.barcode ?? undefined,
            // Toujours envoyé, même produit connu : sans quoi le serveur
            // reviendrait au nom du catalogue et perdrait un renommage local.
            name: line.name.trim(),
            brand: line.brand ?? undefined,
            numberOfPackages: line.numberOfPackages,
            quantityPerPackage: perPackage,
            unit: line.unit,
            storageLocation: line.storageLocation,
            traits: line.traits.length > 0 ? line.traits : undefined,
            expirationDate: line.estimateExpiration || !line.expirationDate ? undefined : line.expirationDate,
            estimateExpiration: line.estimateExpiration || undefined,
            // Une entrée par date : plusieurs exemplaires identiques scannés
            // pour des dates différentes deviennent bien des lots distincts.
            batches:
              line.batches && line.batches.length > 0
                ? line.batches.map((batch) => ({
                    numberOfPackages: Math.max(1, Math.round(Number(batch.numberOfPackages.replace(',', '.')) || 1)),
                    expirationDate: batch.estimateExpiration || !batch.expirationDate ? null : batch.expirationDate,
                    estimateExpiration: batch.estimateExpiration,
                  }))
                : undefined,
          },
          operationKeyFor(line.id),
        );
        removeLine(line.id);

        // Nettoyage seulement : un échec ici ne remet pas en cause l'achat
        // qui, lui, vient de réussir.
        if (line.shoppingListItemId) {
          api.deleteShoppingListItem(line.shoppingListItemId).catch(() => undefined);
        }
      } catch (exception) {
        anyFailed = true;
        setStatuses((current) => ({ ...current, [line.id]: 'error' }));
        setErrors((current) => ({
          ...current,
          [line.id]: exception instanceof Error ? exception.message : 'Ajout impossible.',
        }));
      }
    }

    setBusy(false);

    if (!anyFailed) {
      clear();
      router.replace('/(tabs)');
    } else {
      setGlobalError('Certaines lignes n\'ont pas pu être ajoutées : corrigez-les puis validez à nouveau.');
    }
  }

  const pickerLine = lines.find((line) => line.id === picker?.lineId);
  const pickerBatch =
    null !== picker?.batchId ? pickerLine?.batches?.find((batch) => batch.id === picker?.batchId) : null;
  const pickerValue = picker?.batchId !== null ? pickerBatch?.expirationDate : pickerLine?.expirationDate;

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={globalError} />

      {lines.map((line) => (
        <ShoppingLineCard
          key={line.id}
          line={line}
          reference={reference}
          status={statuses[line.id] ?? 'idle'}
          error={errors[line.id] ?? null}
          busy={busy}
          onChange={(patch) => updateLine(line.id, patch)}
          onRemove={() => removeLine(line.id)}
          onOpenDatePicker={(batchId) => setPicker({ lineId: line.id, batchId })}
        />
      ))}

      {pickerLine ? (
        <DateTimePicker
          value={pickerValue ? fromIsoDate(pickerValue) : addDays(7)}
          mode="date"
          onChange={(event, selected) => {
            const target = picker;
            setPicker(null);
            if ('set' !== event.type || !selected || !target) return;

            if (null === target.batchId) {
              updateLine(target.lineId, { expirationDate: toIsoDate(selected), estimateExpiration: false });
              return;
            }

            updateLine(target.lineId, {
              batches: (pickerLine?.batches ?? []).map((batch) =>
                batch.id === target.batchId
                  ? { ...batch, expirationDate: toIsoDate(selected), estimateExpiration: false }
                  : batch,
              ),
            });
          }}
        />
      ) : null}

      <Button
        label={`Valider ${lines.length} produit${lines.length > 1 ? 's' : ''}`}
        onPress={validateAll}
        loading={busy}
        style={styles.spaced}
      />
    </ScrollView>
  );
}

function ShoppingLineCard({
  line,
  reference,
  status,
  error,
  busy,
  onChange,
  onRemove,
  onOpenDatePicker,
}: {
  line: ShoppingSessionLine;
  reference: Reference | null;
  status: LineStatus;
  error: string | null;
  busy: boolean;
  onChange: (patch: Partial<ShoppingSessionLine>) => void;
  onRemove: () => void;
  onOpenDatePicker: (batchId: number | null) => void;
}) {
  const perPackage = Number(line.quantityPerPackage.replace(',', '.'));
  const differsFromUsual =
    null !== line.usualPackageSize &&
    Number.isFinite(perPackage) &&
    Math.abs(perPackage - line.usualPackageSize) > 0.001;

  const batches = line.batches;

  function startBatches() {
    onChange({
      batches: [
        {
          id: nextBatchId++,
          numberOfPackages: String(line.numberOfPackages),
          expirationDate: line.expirationDate,
          estimateExpiration: line.estimateExpiration,
        },
      ],
    });
  }

  function stopBatches() {
    const first = batches?.[0] ?? null;
    onChange({
      batches: null,
      numberOfPackages: first
        ? Math.max(1, Math.round(Number(first.numberOfPackages.replace(',', '.')) || 1))
        : line.numberOfPackages,
      expirationDate: first?.expirationDate ?? null,
      estimateExpiration: first?.estimateExpiration ?? false,
    });
  }

  function updateBatch(batchId: number, patch: Partial<ShoppingSessionBatch>) {
    onChange({ batches: (batches ?? []).map((batch) => (batch.id === batchId ? { ...batch, ...patch } : batch)) });
  }

  function addBatch() {
    onChange({
      batches: [...(batches ?? []), { id: nextBatchId++, numberOfPackages: '1', expirationDate: null, estimateExpiration: false }],
    });
  }

  function removeBatch(batchId: number) {
    onChange({ batches: (batches ?? []).filter((batch) => batch.id !== batchId) });
  }

  return (
    <Card style={styles.card}>
      <View style={styles.headerRow}>
        <View style={styles.badges}>
          {line.isNew ? (
            <Badge label="Nouveau produit" level="calm" />
          ) : (
            <Badge label="Déjà connu" level="safe" />
          )}
          {line.shoppingListItemId ? <Badge label="Sur votre liste" level="safe" /> : null}
        </View>
        <Pressable onPress={onRemove} accessibilityRole="button" accessibilityLabel="Retirer cette ligne">
          <Text style={styles.removeText}>Retirer</Text>
        </Pressable>
      </View>

      <ErrorBanner message={error} />

      <Field
        label="Nom de l'aliment"
        value={line.name}
        onChangeText={(value) => onChange({ name: value })}
        placeholder="Tomates, riz, reste de gratin…"
      />

      <View style={styles.pair}>
        <Field
          label="Quantité par exemplaire"
          value={line.quantityPerPackage}
          onChangeText={(value) => onChange({ quantityPerPackage: value })}
          keyboardType="decimal-pad"
          containerStyle={styles.pairInput}
        />
        {null === batches ? (
          <View style={styles.pairInput}>
            <Text style={styles.fieldLabel}>Exemplaires</Text>
            <View style={styles.stepper}>
              <Pressable
                style={styles.stepperButton}
                onPress={() => onChange({ numberOfPackages: Math.max(1, line.numberOfPackages - 1) })}
                accessibilityRole="button"
                accessibilityLabel="Un exemplaire de moins"
              >
                <Text style={styles.stepperGlyph}>−1</Text>
              </Pressable>
              <Text style={styles.stepperValue}>{line.numberOfPackages}</Text>
              <Pressable
                style={styles.stepperButton}
                onPress={() => onChange({ numberOfPackages: line.numberOfPackages + 1 })}
                accessibilityRole="button"
                accessibilityLabel="Un exemplaire de plus"
              >
                <Text style={styles.stepperGlyph}>+1</Text>
              </Pressable>
            </View>
          </View>
        ) : null}
      </View>

      {differsFromUsual ? (
        <Text style={styles.hint}>
          Différent de d'habitude ({formatNumber(line.usualPackageSize as number)} {line.unit})
        </Text>
      ) : null}

      {null !== line.requestedQuantity ? (
        <Text style={styles.hint}>
          Demandé : {formatNumber(line.requestedQuantity)} {line.requestedUnit ?? line.unit}
        </Text>
      ) : null}

      <Text style={styles.fieldLabel}>Unité</Text>
      <View style={styles.chips}>
        {(reference?.units ?? []).map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={line.unit === option.value}
            onPress={() => onChange({ unit: option.value as ShoppingSessionLine['unit'] })}
          />
        ))}
      </View>

      <Text style={styles.fieldLabel}>Où est-il rangé ?</Text>
      <View style={styles.chips}>
        {(reference?.storageLocations ?? []).map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={line.storageLocation === option.value}
            onPress={() => onChange({ storageLocation: option.value as ShoppingSessionLine['storageLocation'] })}
          />
        ))}
      </View>

      {null === batches ? (
        <>
          <Text style={styles.fieldLabel}>À consommer avant</Text>
          <Pressable style={styles.dateButton} onPress={() => onOpenDatePicker(null)} accessibilityRole="button">
            <Text style={styles.dateText}>
              {line.expirationDate
                ? formatDate(line.expirationDate)
                : line.estimateExpiration
                  ? 'Estimée automatiquement'
                  : 'Aucune date'}
            </Text>
          </Pressable>
          <View style={styles.chips}>
            <Chip
              label="Pas de date : estimer"
              selected={line.estimateExpiration}
              onPress={() => onChange({ estimateExpiration: true, expirationDate: null })}
            />
            {line.expirationDate || line.estimateExpiration ? (
              <Chip
                label="Effacer"
                selected={false}
                onPress={() => onChange({ expirationDate: null, estimateExpiration: false })}
              />
            ) : null}
            {/* Deux yaourts pour le 27, trois pour le 3 août : un seul scan
                répété, mais deux lots — sinon la date la plus proche ment. */}
            <Chip label="Dates différentes" selected={false} onPress={startBatches} />
          </View>
        </>
      ) : (
        <>
          <Text style={styles.fieldLabel}>Dates de péremption</Text>
          {batches.map((batch, position) => (
            <View key={batch.id} style={styles.batchRow}>
              <View style={styles.batchHeader}>
                <Text style={styles.batchTitle}>Lot {position + 1}</Text>
                {batches.length > 1 ? (
                  <Pressable
                    onPress={() => removeBatch(batch.id)}
                    accessibilityRole="button"
                    accessibilityLabel={`Retirer le lot ${position + 1}`}
                  >
                    <Text style={styles.removeText}>Retirer</Text>
                  </Pressable>
                ) : null}
              </View>
              <View style={styles.pair}>
                <Field
                  label="Exemplaires"
                  value={batch.numberOfPackages}
                  onChangeText={(value) => updateBatch(batch.id, { numberOfPackages: value })}
                  keyboardType="number-pad"
                  containerStyle={styles.pairInput}
                />
                <View style={styles.pairInput}>
                  <Text style={styles.fieldLabel}>À consommer avant</Text>
                  <Pressable
                    style={styles.dateButton}
                    onPress={() => onOpenDatePicker(batch.id)}
                    accessibilityRole="button"
                  >
                    <Text style={styles.dateText}>
                      {batch.expirationDate
                        ? formatDate(batch.expirationDate)
                        : batch.estimateExpiration
                          ? 'Estimée automatiquement'
                          : 'Aucune date'}
                    </Text>
                  </Pressable>
                </View>
              </View>
              <View style={styles.chips}>
                <Chip
                  label="Pas de date : estimer"
                  selected={batch.estimateExpiration}
                  onPress={() => updateBatch(batch.id, { estimateExpiration: true, expirationDate: null })}
                />
                {batch.expirationDate || batch.estimateExpiration ? (
                  <Chip
                    label="Effacer"
                    selected={false}
                    onPress={() => updateBatch(batch.id, { expirationDate: null, estimateExpiration: false })}
                  />
                ) : null}
              </View>
            </View>
          ))}
          <View style={styles.chips}>
            <Chip label="Ajouter une autre date" selected={false} onPress={addBatch} />
            <Chip label="Revenir à une seule date" selected={false} onPress={stopBatches} />
          </View>
        </>
      )}

      {'busy' === status ? <Text style={styles.statusBusy}>Ajout en cours…</Text> : null}
      {'error' === status ? <Text style={styles.statusError}>Échec — corrigez puis validez à nouveau.</Text> : null}
    </Card>
  );
}

function addDays(days: number): Date {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date;
}

function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

/** « 2026-08-02 » interprété localement : `new Date(iso)` serait en UTC, donc décalé d'un jour. */
function fromIsoDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

function formatDate(value: string): string {
  return fromIsoDate(value).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  spaced: { marginTop: spacing.lg },
  card: { marginBottom: spacing.md },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: spacing.md },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, flex: 1 },
  removeText: { ...text.small, color: thermal.critical.line, fontFamily: font.sansMedium },
  fieldLabel: { ...text.label, marginBottom: spacing.sm },
  pair: { flexDirection: 'row', gap: spacing.md },
  pairInput: { flex: 1 },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  hint: { ...text.small, color: thermal.soon.line, marginTop: -spacing.sm, marginBottom: spacing.md },
  batchRow: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    paddingTop: spacing.md,
    marginTop: spacing.md,
  },
  batchHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.sm,
  },
  batchTitle: text.label,
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.sm,
    paddingVertical: 5,
  },
  stepperButton: {
    width: 40,
    height: 36,
    borderRadius: radius.sm,
    backgroundColor: colors.surfaceSunk,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepperGlyph: { fontFamily: font.monoMedium, fontSize: 12, color: colors.ink },
  stepperValue: { fontFamily: font.monoSemiBold, fontSize: 15, color: colors.ink },
  dateButton: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    justifyContent: 'center',
    minHeight: 48,
    marginBottom: spacing.sm,
  },
  dateText: text.body,
  statusBusy: { ...text.small, marginTop: spacing.sm },
  statusError: { ...text.small, color: thermal.critical.line, marginTop: spacing.sm },
});
