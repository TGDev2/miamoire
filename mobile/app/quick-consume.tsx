import { useFocusEffect } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import type { QuickConsumeCandidate } from '../src/api/types';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader } from '../src/components/ui';
import { colors, radius, spacing } from '../src/theme';

/**
 * Le parcours permanent pour une consommation ordinaire — un yaourt, un peu de
 * lait — qui n'a jamais commencé par une recette et n'a rien d'urgent. Sans ce
 * geste rapide, ces événements fréquents restaient non déclarés, et c'était à
 * « Vérifier mon stock » de rattraper la dérive après coup.
 */
export default function QuickConsumeScreen() {
  const [items, setItems] = useState<QuickConsumeCandidate[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [amounts, setAmounts] = useState<Record<string, string>>({});

  const operationKeys = useRef(new Map<string, string>());

  function operationKeyFor(itemId: string, actionId: string): string {
    const mapKey = `${itemId}:${actionId}`;
    const existing = operationKeys.current.get(mapKey);
    if (existing) return existing;

    const key = generateOperationKey();
    operationKeys.current.set(mapKey, key);
    return key;
  }

  // Le stock change depuis d'autres écrans : on recharge à chaque retour sur l'onglet.
  useFocusEffect(
    useCallback(() => {
      setLoadError(null);
      void api
        .quickConsumeCandidates()
        .then((response) => setItems(response.items))
        .catch((exception) => {
          setLoadError(exception instanceof Error ? exception.message : 'Chargement impossible.');
        });
    }, []),
  );

  function removeItem(itemId: string) {
    setItems((current) => (current ?? []).filter((candidate) => candidate.stockItem.id !== itemId));
    setExpandedId((current) => (current === itemId ? null : current));
  }

  async function run(itemId: string, actionId: string, action: (operationKey: string) => Promise<unknown>) {
    setActionError(null);
    setBusy(true);
    const operationKey = operationKeyFor(itemId, actionId);

    try {
      await action(operationKey);
      operationKeys.current.delete(`${itemId}:${actionId}`);
      removeItem(itemId);
    } catch (exception) {
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKeys.current.delete(`${itemId}:${actionId}`);
      }
      setActionError(exception instanceof Error ? exception.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  }

  function confirmOtherQuantity(candidate: QuickConsumeCandidate) {
    const itemId = candidate.stockItem.id;
    const raw = amounts[itemId] ?? '';
    const value = Number(raw.replace(',', '.'));

    if (!Number.isFinite(value) || value <= 0) {
      setActionError('Indiquez une quantité valide.');
      return;
    }

    void run(itemId, 'other-quantity', (operationKey) =>
      api.consume(itemId, { mode: 'quantity', quantity: value }, operationKey),
    );
  }

  if (null === items && !loadError) {
    return <Loader label="Recherche de ce qui a pu être utilisé…" />;
  }

  if (loadError) {
    return (
      <View style={styles.container}>
        <ErrorBanner message={loadError} />
      </View>
    );
  }

  if (0 === items?.length) {
    return (
      <View style={styles.container}>
        <EmptyState
          title="Rien à signaler pour l'instant"
          description="Aucun produit entamé, consulté ou consommé récemment ne ressort. Ouvrez une fiche produit depuis le stock pour la retrouver ici."
        />
      </View>
    );
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ErrorBanner message={actionError} />

      {(items ?? []).map((candidate) => (
        <QuickConsumeCard
          key={candidate.stockItem.id}
          candidate={candidate}
          busy={busy}
          expanded={expandedId === candidate.stockItem.id}
          amount={amounts[candidate.stockItem.id] ?? ''}
          onChangeAmount={(value) =>
            setAmounts((current) => ({ ...current, [candidate.stockItem.id]: value }))
          }
          onToggleOtherQuantity={() =>
            setExpandedId((current) => (current === candidate.stockItem.id ? null : candidate.stockItem.id))
          }
          onConfirmOtherQuantity={() => confirmOtherQuantity(candidate)}
          onOnePackage={() =>
            run(candidate.stockItem.id, 'one-package', (operationKey) =>
              api.consume(candidate.stockItem.id, { mode: 'package' }, operationKey),
            )
          }
          onHalf={() =>
            run(candidate.stockItem.id, 'half', (operationKey) =>
              api.correct(candidate.stockItem.id, { fillLevel: 'half' }, operationKey, candidate.stockItem.revision),
            )
          }
          onFinished={() =>
            run(candidate.stockItem.id, 'finished', (operationKey) =>
              api.consume(candidate.stockItem.id, { mode: 'all' }, operationKey),
            )
          }
        />
      ))}
    </ScrollView>
  );
}

function QuickConsumeCard({
  candidate,
  busy,
  expanded,
  amount,
  onChangeAmount,
  onToggleOtherQuantity,
  onConfirmOtherQuantity,
  onOnePackage,
  onHalf,
  onFinished,
}: {
  candidate: QuickConsumeCandidate;
  busy: boolean;
  expanded: boolean;
  amount: string;
  onChangeAmount: (value: string) => void;
  onToggleOtherQuantity: () => void;
  onConfirmOtherQuantity: () => void;
  onOnePackage: () => void;
  onHalf: () => void;
  onFinished: () => void;
}) {
  const item = candidate.stockItem;

  return (
    <Card style={styles.card}>
      <Text style={styles.name}>{item.variant.culinaryLabel}</Text>
      {item.variant.brand ? <Text style={styles.brand}>{item.variant.brand}</Text> : null}

      <View style={styles.reasons}>
        {candidate.reasons.map((reason) => (
          <Badge key={reason.code} label={reason.label} color={colors.info} background={colors.infoSoft} />
        ))}
      </View>

      <Text style={styles.meta}>
        {item.displayQuantity} {item.displayUnit} · {item.storageLocationLabel}
      </Text>

      <View style={styles.row}>
        <Button label="Un exemplaire" onPress={onOnePackage} disabled={busy} style={styles.rowItem} />
        <Button
          label="Environ la moitié"
          variant="secondary"
          onPress={onHalf}
          disabled={busy}
          style={styles.rowItem}
        />
      </View>
      <View style={styles.row}>
        <Button label="Terminé" variant="danger" onPress={onFinished} disabled={busy} style={styles.rowItem} />
        <Button
          label="Autre quantité"
          variant="ghost"
          onPress={onToggleOtherQuantity}
          disabled={busy}
          style={styles.rowItem}
        />
      </View>

      {expanded ? (
        <View style={styles.amountRow}>
          <TextInput
            value={amount}
            onChangeText={onChangeAmount}
            keyboardType="decimal-pad"
            placeholder={`Quantité en ${item.unitLabel}`}
            placeholderTextColor={colors.inkMuted}
            style={styles.amountInput}
          />
          <Button label="Confirmer" onPress={onConfirmOtherQuantity} disabled={busy} style={styles.amountButton} />
        </View>
      ) : null}
    </Card>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  card: { marginBottom: spacing.lg },
  name: { fontSize: 19, fontWeight: '800', color: colors.ink },
  brand: { fontSize: 14, color: colors.inkMuted, marginTop: 2 },
  reasons: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.md },
  meta: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.md },
  row: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.md },
  rowItem: { flex: 1 },
  amountRow: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.md, alignItems: 'center' },
  amountInput: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 12,
    fontSize: 16,
    color: colors.ink,
  },
  amountButton: { flexShrink: 0 },
});
