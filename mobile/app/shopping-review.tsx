import DateTimePicker from '@react-native-community/datetimepicker';
import { useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api } from '../src/api/client';
import type { GroceryReturnDecision, Reference, ShoppingListItem, StorageLocation, Unit } from '../src/api/types';
import { CutPicker } from '../src/components/CutPicker';
import { useFeedback } from '../src/components/Feedback';
import {
  Badge,
  Button,
  Card,
  Chip,
  EmptyState,
  ErrorBanner,
  Field,
  Loader,
  SectionTitle,
  Stepper,
  formatDisplayDate,
  fromIsoDate,
  toIsoDate,
} from '../src/components/ui';
import * as haptics from '../src/haptics';
import type { ShoppingSessionBatch, ShoppingSessionLine } from '../src/shopping/ShoppingSessionContext';
import { useShoppingSession } from '../src/shopping/ShoppingSessionContext';
import {
  confirmPayloadFor,
  countOf,
  dateIntentOf,
  groupLines,
  isTouched,
  mergeResolved,
  parseCount,
  parseQuantity,
  resolvePayloadFor,
  touch,
} from '../src/shopping/triage';
import { colors, font, radius, spacing, text, thermal } from '../src/theme';

/** A name is typed letter by letter: the server is asked once the typing pauses. */
const NAME_DEBOUNCE_MS = 400;

/** La date en cours d'édition : celle d'une ligne entière, ou celle d'un lot précis. */
interface PickerTarget {
  lineId: string;
  batchId: number | null;
}

type LinePatch = (line: ShoppingSessionLine) => Partial<ShoppingSessionLine>;

/** The questions, should the server send a decision without its label. */
const DECISION_QUESTIONS: Record<Exclude<GroceryReturnDecision, 'none'>, string> = {
  unknown_food: 'Quel est cet aliment ?',
  unit_mismatch: 'Dans quelle unité ?',
  fridge_or_freezer: 'Frigo ou congélateur ?',
  short_date: 'Quelle est la date imprimée ?',
};

/** Until the reference answers, the three places still have a name. */
const STORAGE_FALLBACK: Record<StorageLocation, string> = {
  pantry: 'Placard',
  fridge: 'Réfrigérateur',
  freezer: 'Congélateur',
};

/**
 * Le tri du panier de « Retour de courses ».
 *
 * The basket goes to the server once, which fills every empty field — habits,
 * catalog, estimated date, storage, cut — and asks at most one question per
 * line. The screen shows the lines that still ask something expanded, the
 * rest as one row each, and one button writes everything in one operation.
 * The lines left to decide are not sent: they stay in the basket.
 */
export default function ShoppingReviewScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { notify, commitWithUndo } = useFeedback();
  const { lines, updateLine, removeLine, addListLines, returnOperationKey, completeReturn } = useShoppingSession();

  const [reference, setReference] = useState<Reference | null>(null);
  /** The lines whose resolution is in flight. */
  const [resolving, setResolving] = useState<string[]>([]);
  const [resolveError, setResolveError] = useState<string | null>(null);
  const [confirmError, setConfirmError] = useState<string | null>(null);
  /** The per-line messages of a refused confirmation, by line id. */
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({});
  /** Ready lines opened with « Modifier », and lines to decide opened in full. */
  const [expanded, setExpanded] = useState<string[]>([]);
  /** The lines of a confirmation held by the undo toast: hidden, not yet written. */
  const [sending, setSending] = useState<string[]>([]);
  const [picker, setPicker] = useState<PickerTarget | null>(null);
  /** The ticked lines of the shopping list, offered when the basket is empty. */
  const [checkedListItems, setCheckedListItems] = useState<ShoppingListItem[]>([]);

  // The basket is read through a ref when an answer arrives, never from the
  // render that sent the request: a chip tapped meanwhile must win (lesson 9).
  const linesRef = useRef(lines);
  linesRef.current = lines;
  const mounted = useRef(true);
  /** Per line, the number of the latest resolve request: an older answer is dropped. */
  const requestSequence = useRef(new Map<string, number>());
  /** The lines already sent to resolve at least once since the screen opened. */
  const seen = useRef(new Set<string>());
  /** The lines whose last resolution failed: « Réessayer » sends these. */
  const failed = useRef(new Set<string>());
  const nameTimers = useRef(new Map<string, ReturnType<typeof setTimeout>>());

  useEffect(() => {
    api.reference().then(setReference).catch(() => {});

    return () => {
      mounted.current = false;
      for (const timer of nameTimers.current.values()) clearTimeout(timer);
    };
  }, []);

  const resolveLines = useCallback(
    async (ids: string[]) => {
      const targets = linesRef.current.filter((line) => ids.includes(line.id));
      if (0 === targets.length) return;

      const sequences = new Map<string, number>();
      for (const line of targets) {
        const sequence = (requestSequence.current.get(line.id) ?? 0) + 1;
        requestSequence.current.set(line.id, sequence);
        sequences.set(line.id, sequence);
      }
      const targetIds = targets.map((line) => line.id);
      const isLatest = (id: string) => requestSequence.current.get(id) === sequences.get(id);

      setResolving((current) => Array.from(new Set([...current, ...targetIds])));

      try {
        const response = await api.resolveGroceryReturn(targets.map(resolvePayloadFor));

        for (const resolved of response.lines) {
          // A newer request for this line is in flight: its answer wins.
          if (!isLatest(resolved.lineKey)) continue;

          failed.current.delete(resolved.lineKey);
          updateLine(resolved.lineKey, (line) => mergeResolved(line, resolved));
        }
        setResolveError(null);
      } catch (exception) {
        for (const id of targetIds) {
          if (isLatest(id)) failed.current.add(id);
        }
        // The lines stay as they are, and the screen says it could not know —
        // never that there is nothing to ask (lesson 27).
        setResolveError(exception instanceof Error ? exception.message : 'Vérification impossible.');
      } finally {
        setResolving((current) => current.filter((id) => !(targetIds.includes(id) && isLatest(id))));
      }
    },
    [updateLine],
  );

  // Every line the screen has not sent yet goes to the server: the basket on
  // opening, a line added from the list, a scan while this screen is open.
  useEffect(() => {
    const fresh = lines.filter((line) => !seen.current.has(line.id)).map((line) => line.id);
    if (0 === fresh.length) return;

    for (const id of fresh) seen.current.add(id);
    void resolveLines(fresh);
  }, [lines, resolveLines]);

  const empty = 0 === lines.length;

  useEffect(() => {
    if (!empty) return;

    api
      .shoppingList()
      .then((response) => setCheckedListItems(response.items.filter((item) => item.checked)))
      // Unknown is not empty: the button is simply not offered.
      .catch(() => setCheckedListItems([]));
  }, [empty]);

  function retryResolve() {
    const ids =
      failed.current.size > 0
        ? Array.from(failed.current)
        : linesRef.current.filter((line) => !line.resolution).map((line) => line.id);
    void resolveLines(ids);
  }

  function patchLine(id: string, patch: LinePatch) {
    updateLine(id, patch);
  }

  /** The name changes what the line is: the server is asked again, once the typing pauses. */
  function editName(id: string, name: string) {
    updateLine(id, (line) => ({ name, touched: touch(line, 'name'), decided: false }));

    const existing = nameTimers.current.get(id);
    if (existing) clearTimeout(existing);
    nameTimers.current.set(
      id,
      setTimeout(() => {
        nameTimers.current.delete(id);
        void resolveLines([id]);
      }, NAME_DEBOUNCE_MS),
    );
  }

  /** A unit or a storage changes what the server fills (family, estimate): asked again at once. */
  function editIdentity(id: string, patch: LinePatch) {
    updateLine(id, patch);
    void resolveLines([id]);
  }

  function toggleExpanded(id: string) {
    setExpanded((current) => (current.includes(id) ? current.filter((entry) => entry !== id) : [...current, id]));
  }

  function remove(id: string) {
    haptics.removed();
    removeLine(id);
  }

  const visibleLines = lines.filter((line) => !sending.includes(line.id));
  const { toDecide, ready } = groupLines(visibleLines);

  function confirmReturn() {
    if (0 === ready.length) return;

    const ids = ready.map((line) => line.id);
    const payload = ready.map(confirmPayloadFor);
    const operationKey = returnOperationKey();
    const count = ready.length;
    const plural = count > 1 ? 's' : '';

    setConfirmError(null);
    setLineErrors({});
    setSending(ids);
    haptics.tap();

    // Held five seconds, like every write of the application: « Annuler »
    // prevents the request rather than undoing it. See `Feedback.tsx`.
    commitWithUndo({
      message: `Retour de courses — ${count} produit${plural} à ranger`,
      tone: 'safe',
      commit: async () => {
        try {
          // A replay (`replayed: true`) is the same success: nothing is written twice.
          await api.confirmGroceryReturn(payload, operationKey);
        } catch (exception) {
          // Nothing was written: each refused line gets its message, the key stays.
          if (exception instanceof ApiError && 422 === exception.status) {
            setLineErrors(Object.fromEntries(exception.lineErrors.map((entry) => [entry.lineKey, entry.message])));
          }
          throw exception;
        }

        completeReturn(ids);
        setSending([]);
        haptics.success();
        notify(`${count} produit${plural} rangé${plural}`, 'safe');
        if (mounted.current) router.replace('/(tabs)');
      },
      onUndo: () => setSending([]),
      onFailed: (message) => {
        setSending([]);
        setConfirmError(message);
      },
    });
  }

  if (empty) {
    const checked = checkedListItems.length;

    return (
      <View style={styles.container}>
        <EmptyState
          icon="basket-outline"
          title="Panier vide"
          description="Scannez vos courses, ou partez des lignes déjà cochées de votre liste."
          action={
            <>
              <Button label="Scanner" icon="barcode-scan" onPress={() => router.replace('/scan')} />
              {checked > 0 ? (
                <Button
                  label={`Partir de la liste (${checked} cochée${checked > 1 ? 's' : ''})`}
                  variant="secondary"
                  icon="format-list-checks"
                  onPress={() => addListLines(checkedListItems)}
                  hint="Ajoute au retour les lignes cochées de la liste de courses"
                  style={styles.spaced}
                />
              ) : null}
            </>
          }
        />
      </View>
    );
  }

  const pickerLine = lines.find((line) => line.id === picker?.lineId);
  const pickerBatch =
    null !== picker?.batchId ? pickerLine?.batches?.find((batch) => batch.id === picker?.batchId) : null;
  const pickerValue = picker?.batchId !== null ? pickerBatch?.expirationDate : pickerLine?.expirationDate;

  const readyCount = ready.length;
  const pendingCount = toDecide.length;
  const buttonLabel =
    0 === readyCount
      ? `Aucun produit prêt · ${pendingCount} à préciser`
      : `Ranger ${readyCount} produit${readyCount > 1 ? 's' : ''}${pendingCount > 0 ? ` · ${pendingCount} à préciser` : ''}`;

  return (
    <View style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <ErrorBanner message={confirmError} />

        {resolveError ? (
          <View style={styles.retry}>
            <ErrorBanner message={resolveError} />
            <Button label="Réessayer la vérification" variant="secondary" icon="refresh" onPress={retryResolve} />
          </View>
        ) : null}

        {resolving.length > 0 ? (
          <View style={styles.resolvingRow}>
            <Loader label="Vérification du panier" />
          </View>
        ) : null}

        {sending.length > 0 ? (
          <Card level="safe" style={styles.card}>
            <Text style={text.body}>
              {sending.length} produit{sending.length > 1 ? 's' : ''} en cours de rangement — quelques secondes pour
              annuler.
            </Text>
          </Card>
        ) : null}

        {toDecide.length > 0 ? <SectionTitle>{`À préciser · ${toDecide.length}`}</SectionTitle> : null}
        {toDecide.map((line) => (
          <LineEditor
            key={line.id}
            line={line}
            reference={reference}
            error={lineErrors[line.id] ?? null}
            mode={expanded.includes(line.id) ? 'full' : 'decide'}
            resolving={resolving.includes(line.id)}
            onPatch={(patch) => patchLine(line.id, patch)}
            onNameChange={(name) => editName(line.id, name)}
            onIdentityChange={(patch) => editIdentity(line.id, patch)}
            onRemove={() => remove(line.id)}
            onOpenDatePicker={(batchId) => setPicker({ lineId: line.id, batchId })}
            onToggleMode={() => toggleExpanded(line.id)}
          />
        ))}

        {ready.length > 0 ? <SectionTitle>{`Rangées d'avance · ${ready.length}`}</SectionTitle> : null}
        {ready.map((line) =>
          expanded.includes(line.id) || line.id in lineErrors ? (
            <LineEditor
              key={line.id}
              line={line}
              reference={reference}
              error={lineErrors[line.id] ?? null}
              mode="full"
              resolving={resolving.includes(line.id)}
              onPatch={(patch) => patchLine(line.id, patch)}
              onNameChange={(name) => editName(line.id, name)}
              onIdentityChange={(patch) => editIdentity(line.id, patch)}
              onRemove={() => remove(line.id)}
              onOpenDatePicker={(batchId) => setPicker({ lineId: line.id, batchId })}
              onToggleMode={() => setExpanded((current) => current.filter((entry) => entry !== line.id))}
            />
          ) : (
            <ReadyRow key={line.id} line={line} reference={reference} onEdit={() => toggleExpanded(line.id)} />
          ),
        )}

        {pickerLine ? (
          <DateTimePicker
            value={pickerValue ? fromIsoDate(pickerValue) : addDays(7)}
            mode="date"
            onChange={(event, selected) => {
              const target = picker;
              setPicker(null);
              if ('set' !== event.type || !selected || !target) return;

              const iso = toIsoDate(selected);

              if (null === target.batchId) {
                updateLine(target.lineId, (line) => ({
                  expirationDate: iso,
                  estimateExpiration: false,
                  touched: touch(line, 'expirationDate'),
                  decided: answers(line, 'short_date'),
                }));
                return;
              }

              updateLine(target.lineId, (line) => ({
                batches: (line.batches ?? []).map((batch) =>
                  batch.id === target.batchId ? { ...batch, expirationDate: iso, estimateExpiration: false } : batch,
                ),
              }));
            }}
          />
        ) : null}
      </ScrollView>

      <View style={[styles.footer, { paddingBottom: spacing.lg + insets.bottom }]}>
        <Button
          label={buttonLabel}
          icon="package-variant-closed"
          onPress={confirmReturn}
          disabled={resolving.length > 0 || 0 === readyCount || sending.length > 0}
          hint={pendingCount > 0 ? 'Les lignes à préciser restent dans le panier' : undefined}
        />
      </View>
    </View>
  );
}

/** `true` when the line's open question is this one: the action just answered it. */
function answers(line: ShoppingSessionLine, decision: GroceryReturnDecision): boolean {
  return line.resolution?.decision === decision ? true : true === line.decided;
}

/** One ready line, as a row: name, copies × quantity, place, date and where each comes from. */
function ReadyRow({
  line,
  reference,
  onEdit,
}: {
  line: ShoppingSessionLine;
  reference: Reference | null;
  onEdit: () => void;
}) {
  const unitLabel = reference?.units.find((option) => option.value === line.unit)?.label ?? line.unit;
  const storage = storageLabel(reference, line.storageLocation);
  const date = dateSummary(line);
  const captions = provenanceCaptions(line);
  const meta = `${countOf(line)} × ${line.quantityPerPackage} ${unitLabel} · ${storage} · ${date.label}`;

  return (
    <Card style={styles.rowCard}>
      <Pressable
        onPress={onEdit}
        accessibilityRole="button"
        accessibilityLabel={`${line.name}, ${meta}${captions.length > 0 ? `, ${captions.join(', ')}` : ''}`}
        accessibilityHint="Modifier cette ligne"
        style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      >
        <View style={styles.rowText}>
          <Text style={text.subheading} numberOfLines={1}>
            {line.name}
          </Text>
          <Text style={[text.data, styles.rowMeta]} numberOfLines={1}>
            {meta}
          </Text>
          {captions.length > 0 ? (
            <Text style={[text.small, styles.rowCaption]} numberOfLines={1}>
              {captions.join(' · ')}
            </Text>
          ) : null}
        </View>
        <Text style={styles.editText}>Modifier</Text>
      </Pressable>
    </Card>
  );
}

/**
 * One line, open. `decide` shows only what its question needs — the name for
 * an unknown food, two places for a protein, the date for a short one, the
 * units of the family — plus the quantity and the copies. `full` is the whole
 * editor, as « Modifier » opens it on a ready line.
 */
function LineEditor({
  line,
  reference,
  error,
  mode,
  resolving,
  onPatch,
  onNameChange,
  onIdentityChange,
  onRemove,
  onOpenDatePicker,
  onToggleMode,
}: {
  line: ShoppingSessionLine;
  reference: Reference | null;
  error: string | null;
  mode: 'decide' | 'full';
  resolving: boolean;
  onPatch: (patch: LinePatch) => void;
  onNameChange: (name: string) => void;
  onIdentityChange: (patch: LinePatch) => void;
  onRemove: () => void;
  onOpenDatePicker: (batchId: number | null) => void;
  onToggleMode: () => void;
}) {
  const full = 'full' === mode;
  const resolution = line.resolution ?? null;
  const open = null !== resolution && 'none' !== resolution.decision && true !== line.decided ? resolution.decision : null;
  const question = questionFor(line, resolving);

  const perPackage = parseQuantity(line.quantityPerPackage);
  const differsFromUsual =
    null !== line.usualPackageSize && null !== perPackage && Math.abs(perPackage - line.usualPackageSize) > 0.001;

  const units = (reference?.units ?? []).filter(
    (option) => null === resolution || option.family === resolution.unitFamily,
  );
  const storages = reference?.storageLocations ?? [];
  const cutOptions = (reference?.productCuts ?? []).filter((option) =>
    (resolution?.plausibleCuts ?? []).includes(option.value),
  );
  const askCut = (resolution?.cutMatters ?? false) && null === line.cut;

  const batches = line.batches;
  const intent = dateIntentOf(line);
  const date = dateSummary(line);
  const estimate = resolution?.estimatedExpirationDate ?? null;

  const showName = full || '' === line.name.trim() || 'unknown_food' === open;
  const showUnits = full || 'unit_mismatch' === open;
  const showStorage = full || 'fridge_or_freezer' === open;
  const showDate = full || 'short_date' === open || null !== batches;

  function startBatches() {
    onPatch((current) => {
      const currentIntent = dateIntentOf(current);
      return {
        batches: [
          {
            id: 1,
            numberOfPackages: String(current.numberOfPackages),
            expirationDate: currentIntent.expirationDate,
            estimateExpiration: currentIntent.estimateExpiration,
          },
        ],
      };
    });
  }

  function stopBatches() {
    onPatch((current) => {
      const first = current.batches?.[0] ?? null;
      return {
        batches: null,
        numberOfPackages: first ? parseCount(first.numberOfPackages) : current.numberOfPackages,
        expirationDate: first?.expirationDate ?? null,
        estimateExpiration: first?.estimateExpiration ?? true,
        touched: touch(current, 'expirationDate'),
      };
    });
  }

  function updateBatch(batchId: number, patch: Partial<ShoppingSessionBatch>) {
    onPatch((current) => ({
      batches: (current.batches ?? []).map((batch) => (batch.id === batchId ? { ...batch, ...patch } : batch)),
    }));
  }

  function addBatch() {
    onPatch((current) => {
      const existing = current.batches ?? [];
      const id = Math.max(0, ...existing.map((batch) => batch.id)) + 1;
      return { batches: [...existing, { id, numberOfPackages: '1', expirationDate: null, estimateExpiration: true }] };
    });
  }

  function removeBatch(batchId: number) {
    onPatch((current) => ({ batches: (current.batches ?? []).filter((batch) => batch.id !== batchId) }));
  }

  function batchDateLabel(batch: ShoppingSessionBatch): string {
    if (batch.expirationDate) return formatDisplayDate(batch.expirationDate);
    if (batch.estimateExpiration) return estimate ? `vers le ${formatCompactDate(estimate)}` : 'Estimée automatiquement';
    return 'Aucune date';
  }

  return (
    <Card level={null !== open ? 'soon' : undefined} style={styles.card}>
      <View style={styles.headerRow}>
        <View style={styles.badges}>
          {/* Le code seul dit quel produit attend un nom : sans lui, une ligne
              inconnue ressemble à un objet vide ajouté par erreur. */}
          {line.isUnknown && line.barcode && '' === line.name.trim() ? (
            <Badge label={`Code inconnu · ${line.barcode}`} level="soon" />
          ) : line.isNew ? (
            <Badge label="Nouveau produit" level="calm" />
          ) : (
            <Badge label="Déjà connu" level="safe" />
          )}
          {line.shoppingListItemId ? <Badge label="Sur votre liste" level="safe" /> : null}
        </View>
        <Pressable
          onPress={onRemove}
          hitSlop={8}
          accessibilityRole="button"
          accessibilityLabel={`Retirer ${line.name || 'cette ligne'} du panier`}
          style={styles.removeButton}
        >
          <Text style={styles.removeText}>Retirer</Text>
        </Pressable>
      </View>

      <ErrorBanner message={error} />

      {question ? (
        <Text style={[styles.question, null === open && styles.questionQuiet]} accessibilityRole="header">
          {question}
        </Text>
      ) : null}

      {showName ? (
        <Field
          label="Nom de l'aliment"
          value={line.name}
          onChangeText={onNameChange}
          placeholder="Tomates, riz, reste de gratin…"
        />
      ) : null}

      {/* Right under the name: the cut is part of what the food is, and the
          product is in hand right now — the one moment it costs a single tap. */}
      {(full || askCut) && cutOptions.length > 0 ? (
        <View style={styles.cut}>
          <CutPicker
            options={cutOptions}
            value={line.cut}
            onChange={(cut) => onPatch((current) => ({ cut, touched: touch(current, 'cut') }))}
            collapsible
            ask={resolution?.cutMatters ?? false}
          />
        </View>
      ) : null}

      {'fridge_or_freezer' === open && !full ? (
        <View style={styles.choices}>
          {(['fridge', 'freezer'] as const).map((location) => (
            <ChoiceTile
              key={location}
              label={storageLabel(reference, location)}
              // Before the answer the line already carries the server's default: only a tap selects.
              selected={line.storageLocation === location && isTouched(line, 'storageLocation')}
              onPress={() =>
                onIdentityChange((current) => ({
                  storageLocation: location,
                  touched: touch(current, 'storageLocation'),
                  decided: answers(current, 'fridge_or_freezer'),
                }))
              }
            />
          ))}
        </View>
      ) : null}

      <Field
        label="Quantité par exemplaire"
        value={line.quantityPerPackage}
        onChangeText={(value) => onPatch((current) => ({ quantityPerPackage: value, touched: touch(current, 'quantityPerPackage') }))}
        keyboardType="decimal-pad"
        placeholder="ex. 500"
        hint={
          differsFromUsual
            ? `Différent de d'habitude (${formatNumber(line.usualPackageSize as number)} ${line.unit})`
            : null !== line.requestedQuantity
              ? `Demandé : ${formatNumber(line.requestedQuantity)} ${line.requestedUnit ?? line.unit}`
              : undefined
        }
      />

      {null === batches ? (
        <Stepper
          label="Exemplaires"
          value={line.numberOfPackages}
          unitLabel={line.numberOfPackages > 1 ? 'exemplaires' : 'exemplaire'}
          onChange={(value) => onPatch(() => ({ numberOfPackages: value }))}
        />
      ) : null}

      {showUnits ? (
        <>
          <Text style={styles.fieldLabel}>Unité</Text>
          <View style={styles.chips}>
            {units.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={line.unit === option.value}
                onPress={() =>
                  onIdentityChange((current) => ({
                    unit: option.value as Unit,
                    touched: touch(current, 'unit'),
                    decided: answers(current, 'unit_mismatch'),
                  }))
                }
              />
            ))}
          </View>
        </>
      ) : null}

      {showStorage && full ? (
        <>
          <Text style={styles.fieldLabel}>Où est-il rangé ?</Text>
          <View style={styles.chips}>
            {storages.map((option) => (
              <Chip
                key={option.value}
                label={option.label}
                selected={line.storageLocation === option.value}
                onPress={() =>
                  onIdentityChange((current) => ({
                    storageLocation: option.value as StorageLocation,
                    touched: touch(current, 'storageLocation'),
                    decided: answers(current, 'fridge_or_freezer'),
                  }))
                }
              />
            ))}
          </View>
          {resolution && 'given' !== resolution.provenance.storage && !isTouched(line, 'storageLocation') ? (
            <Text style={styles.provenance}>
              {'habit' === resolution.provenance.storage ? "Comme d'habitude" : 'Emplacement par défaut'}
            </Text>
          ) : null}
        </>
      ) : null}

      {showDate && null === batches ? (
        <>
          <Text style={styles.fieldLabel}>À consommer avant</Text>
          <View style={styles.dateRow} accessible accessibilityLabel={`${date.label}${date.caption ? `, ${date.caption}` : ''}`}>
            <Text style={text.body}>{date.label}</Text>
            {date.caption ? <Text style={styles.dateCaption}>{date.caption}</Text> : null}
          </View>
          {intent.estimateExpiration && resolution?.expirationExplanation ? (
            <Text style={styles.explanation}>{resolution.expirationExplanation}</Text>
          ) : null}
          <View style={styles.chips}>
            <Chip label="Date imprimée…" selected={null !== intent.expirationDate} onPress={() => onOpenDatePicker(null)} />
            {'short_date' === open ? (
              <Chip
                label="Garder l'estimation"
                selected={false}
                onPress={() => onPatch(() => ({ decided: true }))}
              />
            ) : null}
            {full ? (
              <>
                <Chip
                  label="Pas de date : estimer"
                  selected={intent.estimateExpiration}
                  onPress={() =>
                    onPatch((current) => ({
                      estimateExpiration: true,
                      expirationDate: null,
                      touched: touch(current, 'expirationDate'),
                    }))
                  }
                />
                {null !== intent.expirationDate || intent.estimateExpiration ? (
                  <Chip
                    label="Effacer"
                    selected={false}
                    onPress={() =>
                      onPatch((current) => ({
                        expirationDate: null,
                        estimateExpiration: false,
                        touched: touch(current, 'expirationDate'),
                      }))
                    }
                  />
                ) : null}
                {/* Deux yaourts pour le 27, trois pour le 3 août : un seul scan
                    répété, mais deux lots — sinon la date la plus proche ment. */}
                <Chip label="Dates différentes" selected={false} onPress={startBatches} />
              </>
            ) : null}
          </View>
        </>
      ) : null}

      {null !== batches ? (
        <>
          <Text style={styles.fieldLabel}>Dates de péremption</Text>
          {batches.map((batch, position) => (
            <View key={batch.id} style={styles.batchRow}>
              <View style={styles.batchHeader}>
                <Text style={styles.batchTitle}>Lot {position + 1}</Text>
                {batches.length > 1 ? (
                  <Pressable
                    onPress={() => removeBatch(batch.id)}
                    hitSlop={8}
                    accessibilityRole="button"
                    accessibilityLabel={`Retirer le lot ${position + 1}`}
                    style={styles.removeButton}
                  >
                    <Text style={styles.removeText}>Retirer</Text>
                  </Pressable>
                ) : null}
              </View>
              <Field
                label="Exemplaires"
                value={batch.numberOfPackages}
                onChangeText={(value) => updateBatch(batch.id, { numberOfPackages: value })}
                keyboardType="number-pad"
              />
              <Text style={styles.fieldLabel}>À consommer avant</Text>
              <Pressable
                style={styles.dateButton}
                onPress={() => onOpenDatePicker(batch.id)}
                accessibilityRole="button"
                accessibilityLabel={`Date du lot ${position + 1} : ${batchDateLabel(batch)}`}
                accessibilityHint="Choisir la date imprimée"
              >
                <Text style={text.body}>{batchDateLabel(batch)}</Text>
              </Pressable>
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
      ) : null}

      <Pressable
        onPress={onToggleMode}
        accessibilityRole="button"
        accessibilityLabel={full ? 'Réduire cette ligne' : 'Plus d\'options pour cette ligne'}
        style={styles.modeToggle}
      >
        <Text style={styles.modeToggleText}>{full ? 'Réduire' : 'Plus d\'options ›'}</Text>
      </Pressable>
    </Card>
  );
}

/** A choice between two places, wide enough to tap with a product in the other hand. */
function ChoiceTile({ label, selected, onPress }: { label: string; selected: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ selected }}
      style={({ pressed }) => [
        styles.choice,
        selected ? styles.choiceSelected : null,
        pressed && !selected && styles.choicePressed,
      ]}
    >
      <Text style={[styles.choiceText, selected && styles.choiceTextSelected]}>{label}</Text>
    </Pressable>
  );
}

/** What the line still asks, as a sentence; `null` when it is ready. */
function questionFor(line: ShoppingSessionLine, resolving: boolean): string | null {
  if (!line.resolution) return resolving ? 'Vérification en cours…' : 'Pas encore vérifié';

  const { decision, decisionLabel } = line.resolution;
  if ('none' !== decision && true !== line.decided) return decisionLabel ?? DECISION_QUESTIONS[decision];
  if ('' === line.name.trim()) return DECISION_QUESTIONS.unknown_food;
  if (null === parseQuantity(line.quantityPerPackage)) return 'Quelle quantité par exemplaire ?';

  return null;
}

/** The date the line shows, and what it is: printed, estimated, or none. */
function dateSummary(line: ShoppingSessionLine): { label: string; caption: string | null } {
  const intent = dateIntentOf(line);

  if (null !== intent.expirationDate) {
    return { label: formatCompactDate(intent.expirationDate), caption: 'date imprimée' };
  }
  if (!intent.estimateExpiration) return { label: 'aucune date', caption: null };

  const estimate = line.resolution?.estimatedExpirationDate ?? null;
  if (null !== estimate) return { label: `vers le ${formatCompactDate(estimate)}`, caption: 'date estimée' };

  return line.resolution ? { label: 'sans date connue', caption: null } : { label: 'date à estimer', caption: null };
}

/** Where the filled values come from, as small words — never as a colour. */
function provenanceCaptions(line: ShoppingSessionLine): string[] {
  const provenance = line.resolution?.provenance;
  if (!provenance) return [];

  const captions: string[] = [];
  if ('habit' === provenance.package || 'habit' === provenance.storage) captions.push("comme d'habitude");
  else if ('catalog' === provenance.package) captions.push("d'après le catalogue");
  else if ('list' === provenance.package) captions.push("d'après la liste");

  const date = dateSummary(line).caption;
  if (date) captions.push(date);

  return captions;
}

function storageLabel(reference: Reference | null, location: StorageLocation): string {
  return reference?.storageLocations.find((option) => option.value === location)?.label ?? STORAGE_FALLBACK[location];
}

function addDays(days: number): Date {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date;
}

/** « 5 nov. » — the row is short, the year never matters within a basket. */
function formatCompactDate(iso: string): string {
  return fromIsoDate(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
}

function formatNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  container: { padding: spacing.xl, paddingBottom: spacing.xl, flexGrow: 1 },
  spaced: { marginTop: spacing.sm },
  retry: { marginBottom: spacing.lg },
  resolvingRow: { minHeight: 72, marginBottom: spacing.sm },
  card: { marginBottom: spacing.md },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: spacing.md },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, flex: 1 },
  removeButton: { minHeight: 44, justifyContent: 'center', paddingHorizontal: spacing.sm },
  removeText: { ...text.small, color: thermal.critical.line, fontFamily: font.sansMedium },
  question: { ...text.heading, color: thermal.soon.line, marginBottom: spacing.md },
  questionQuiet: { color: colors.inkMuted },
  fieldLabel: { ...text.label, marginBottom: spacing.sm },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  cut: { marginBottom: spacing.md },
  provenance: { ...text.small, marginTop: -spacing.xs, marginBottom: spacing.md },
  choices: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.lg },
  choice: {
    flex: 1,
    minHeight: 56,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: spacing.md,
  },
  choiceSelected: { backgroundColor: colors.ink, borderColor: colors.ink },
  choicePressed: { backgroundColor: colors.surfaceSunk },
  choiceText: { ...text.subheading },
  choiceTextSelected: { color: colors.onInk },
  dateRow: {
    flexDirection: 'row',
    alignItems: 'baseline',
    flexWrap: 'wrap',
    gap: spacing.sm,
    minHeight: 32,
    marginBottom: spacing.xs,
  },
  dateCaption: { ...text.label },
  explanation: { ...text.small, marginBottom: spacing.sm },
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
  modeToggle: { minHeight: 44, justifyContent: 'center', alignSelf: 'flex-start', marginTop: spacing.xs },
  modeToggleText: { ...text.small, color: colors.ink, fontFamily: font.sansMedium },
  rowCard: { marginBottom: spacing.sm, padding: 0 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    minHeight: 56,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
  },
  rowPressed: { backgroundColor: colors.surfaceSunk },
  rowText: { flex: 1 },
  rowMeta: { marginTop: 2 },
  rowCaption: { marginTop: 2 },
  editText: { ...text.small, color: colors.ink, fontFamily: font.sansMedium },
  footer: {
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.md,
    backgroundColor: colors.surface,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
});
