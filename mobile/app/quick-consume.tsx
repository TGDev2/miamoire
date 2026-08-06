import { useFocusEffect } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import type { QuickConsumeCandidate } from '../src/api/types';
import { useFeedback } from '../src/components/Feedback';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader, Stamp } from '../src/components/ui';
import { deadlineOf } from '../src/deadline';
import * as haptics from '../src/haptics';
import { colors, radius, spacing, text, thermalFor } from '../src/theme';

/**
 * Le parcours permanent pour une consommation ordinaire — un yaourt, un peu de
 * lait — qui n'a jamais commencé par une recette et n'a rien d'urgent. Sans ce
 * geste rapide, ces événements fréquents restaient non déclarés, et c'était à
 * « Vérifier mon stock » de rattraper la dérive après coup.
 */
export default function QuickConsumeScreen() {
  const { notify } = useFeedback();

  const [items, setItems] = useState<QuickConsumeCandidate[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  /**
   * L'identifiant du lot en cours, et non un booléen : un seul drapeau gelait
   * les quarante boutons de l'écran sans dire lequel travaillait.
   */
  const [busyId, setBusyId] = useState<string | null>(null);
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
    // Sans cette purge, revenir sur l'écran repréremplissait le champ « Autre
    // quantité » avec le chiffre saisi pour la fois précédente.
    setAmounts((current) => {
      const { [itemId]: removed, ...rest } = current;
      return rest;
    });
  }

  async function run(
    itemId: string,
    actionId: string,
    /** Ce qui vient de se passer, au passé. La carte disparaît sans cela sans un mot. */
    message: string,
    action: (operationKey: string) => Promise<unknown>,
  ) {
    setActionError(null);
    setBusyId(itemId);
    const operationKey = operationKeyFor(itemId, actionId);

    try {
      await action(operationKey);
      operationKeys.current.delete(`${itemId}:${actionId}`);
      removeItem(itemId);
      haptics.success();
      notify(message);
    } catch (exception) {
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKeys.current.delete(`${itemId}:${actionId}`);
      }
      setActionError(exception instanceof Error ? exception.message : 'Action impossible.');
    } finally {
      setBusyId(null);
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

    void run(
      itemId,
      'other-quantity',
      `${candidate.stockItem.variant.culinaryLabel} — ${value} ${candidate.stockItem.displayUnit} retirés`,
      (operationKey) => api.consume(itemId, { mode: 'quantity', quantity: value }, operationKey),
    );
  }

  if (null === items && !loadError) {
    return <Loader label="Recherche de ce qui a pu être utilisé" />;
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
          icon="silverware-fork-knife"
          title="Rien à signaler pour l'instant"
          description="Aucun produit entamé, consulté ou consommé récemment ne ressort. Ouvrez une fiche depuis le stock pour la retrouver ici."
        />
      </View>
    );
  }

  return (
    // Le champ « Autre quantité » et son bouton « Confirmer » sont sur la même
    // ligne : sans cela, le premier appui sur « Confirmer » ne faisait que
    // replier le clavier, et il en fallait un second.
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={actionError} />

      {(items ?? []).map((candidate) => (
        <QuickConsumeCard
          key={candidate.stockItem.id}
          candidate={candidate}
          busy={busyId === candidate.stockItem.id}
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
            run(
              candidate.stockItem.id,
              'one-package',
              `${candidate.stockItem.variant.culinaryLabel} — un de moins`,
              (operationKey) => api.consume(candidate.stockItem.id, { mode: 'package' }, operationKey),
            )
          }
          onHalf={() =>
            run(
              candidate.stockItem.id,
              'half',
              `${candidate.stockItem.variant.culinaryLabel} — corrigé à la moitié`,
              (operationKey) =>
                api.correct(
                  candidate.stockItem.id,
                  { fillLevel: 'half' },
                  operationKey,
                  candidate.stockItem.revision,
                ),
            )
          }
          onFinished={() =>
            run(
              candidate.stockItem.id,
              'finished',
              `${candidate.stockItem.variant.culinaryLabel} — terminé`,
              (operationKey) => api.consume(candidate.stockItem.id, { mode: 'all' }, operationKey),
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
  const deadline = deadlineOf(item);

  return (
    <Card level={thermalFor(deadline.days)} style={styles.card}>
      <View style={styles.headerRow}>
        <View style={styles.flex}>
          <Text style={text.heading}>{item.variant.culinaryLabel}</Text>
          {item.variant.brand ? <Text style={[text.small, styles.brand]}>{item.variant.brand}</Text> : null}
        </View>
        <Stamp days={deadline.days} date={deadline.date} estimated={deadline.estimated} />
      </View>

      <Text style={[text.data, styles.meta]}>
        {item.quantityIsEstimated ? '~' : ''}
        {item.displayQuantity} {item.displayUnit} · {item.storageLocationLabel}
      </Text>

      <View style={styles.reasons}>
        {candidate.reasons.map((reason) => (
          <Badge key={reason.code} label={reason.label} level="calm" />
        ))}
      </View>

      <View style={styles.row}>
        {/* Un produit frais n'a pas d'exemplaire : on en retire un — une courgette,
            une portion — pas « un exemplaire ». Le geste serveur est le même. */}
        <Button
          label={
            item.variant.nature === 'fresh'
              ? item.isDiscreteUnit
                ? `Un(e) ${item.unitLabel === 'unité' ? item.variant.name.toLowerCase() : item.unitLabel}`
                : 'Une portion'
              : 'Un exemplaire'
          }
          onPress={onOnePackage}
          disabled={busy}
          style={styles.rowItem}
        />
        {/* « Environ la moitié » corrige ce qu'il reste, « Terminé » retire
            tout : deux gestes de natures opposées, rendus jusqu'ici avec
            exactement le même poids et les mêmes mots. Le verbe les sépare. */}
        <Button
          label="Il en reste la moitié"
          variant="secondary"
          onPress={onHalf}
          disabled={busy}
          style={styles.rowItem}
        />
      </View>
      <View style={styles.row}>
        <Button
          label="J'ai tout fini"
          variant="secondary"
          onPress={onFinished}
          disabled={busy}
          style={styles.rowItem}
        />
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
  card: { marginBottom: spacing.md },
  flex: { flex: 1 },
  headerRow: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  brand: { marginTop: 2 },
  reasons: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.sm },
  meta: { marginTop: spacing.sm },
  row: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.md },
  rowItem: { flex: 1 },
  amountRow: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.md, alignItems: 'center' },
  amountInput: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    minHeight: 48,
    ...text.body,
  },
  amountButton: { flexShrink: 0 },
});
