import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../../src/api/client';
import type { SaveTodayCandidate, StockItem } from '../../src/api/types';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader } from '../../src/components/ui';
import { useRecipes } from '../../src/recipes/RecipeContext';
import { colors, radius, spacing } from '../../src/theme';

/**
 * « Qu'est-ce que je dois faire maintenant ? » — un petit nombre de lots à
 * traiter, tous visibles à la fois (contrairement à `/review`, une file
 * une-à-une) : il faut voir l'ensemble pour choisir quoi cuisiner avec eux.
 */
export default function TodayScreen() {
  const router = useRouter();
  const { setSuggestion } = useRecipes();

  const [items, setItems] = useState<SaveTodayCandidate[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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
        .saveToday()
        .then((response) => setItems(response.items))
        .catch((exception) => {
          setLoadError(exception instanceof Error ? exception.message : 'Chargement impossible.');
        });
    }, []),
  );

  function removeItem(itemId: string) {
    setItems((current) => (current ?? []).filter((candidate) => candidate.stockItem.id !== itemId));
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

  async function findAMeal() {
    if (!items || items.length === 0) return;

    setActionError(null);
    setBusy(true);

    try {
      const priorityKeys = Array.from(new Set(items.map((candidate) => candidate.stockItem.variant.key)));
      const result = await api.suggestRecipes({
        people: 2,
        mealType: null,
        maxMinutes: null,
        equipment: [],
        exclusions: [],
        allowExtraIngredients: true,
        priorityKeys,
      });
      setSuggestion(result);
      router.push('/recipes');
    } catch (exception) {
      setActionError(exception instanceof Error ? exception.message : 'Génération impossible.');
    } finally {
      setBusy(false);
    }
  }

  if (null === items && !loadError) {
    return <Loader label="Recherche de ce qui presse…" />;
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
          title="Rien à sauver aujourd'hui"
          description="Votre stock ne signale aucune urgence pour le moment."
        />
      </View>
    );
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <ErrorBanner message={actionError} />

      {(items ?? []).map((candidate) => (
        <TodayCard
          key={candidate.stockItem.id}
          candidate={candidate}
          busy={busy}
          onConsumed={() =>
            run(candidate.stockItem.id, 'consumed', (operationKey) =>
              api.consume(candidate.stockItem.id, { mode: 'all' }, operationKey),
            )
          }
          onSnoozed={() =>
            run(candidate.stockItem.id, 'snoozed', (operationKey) =>
              api.snoozeStockItem(candidate.stockItem.id, operationKey),
            )
          }
          onDiscarded={() =>
            run(candidate.stockItem.id, 'discarded', (operationKey) =>
              api.discard(candidate.stockItem.id, operationKey, 'Jeté depuis Aujourd\'hui'),
            )
          }
          onFrozen={() =>
            run(candidate.stockItem.id, 'frozen', (operationKey) =>
              api.freezeStockItem(candidate.stockItem.id, operationKey),
            )
          }
          onCheckDetail={() => router.push(`/item/${candidate.stockItem.id}`)}
        />
      ))}

      <Button
        label="Trouver un repas avec ces aliments"
        onPress={findAMeal}
        disabled={busy || !items || items.length === 0}
        style={styles.spaced}
      />
    </ScrollView>
  );
}

function TodayCard({
  candidate,
  busy,
  onConsumed,
  onSnoozed,
  onDiscarded,
  onFrozen,
  onCheckDetail,
}: {
  candidate: SaveTodayCandidate;
  busy: boolean;
  onConsumed: () => void;
  onSnoozed: () => void;
  onDiscarded: () => void;
  onFrozen: () => void;
  onCheckDetail: () => void;
}) {
  const item = candidate.stockItem;

  return (
    <Card style={styles.card}>
      <Text style={styles.name}>{item.variant.culinaryLabel}</Text>
      {item.variant.brand ? <Text style={styles.brand}>{item.variant.brand}</Text> : null}

      <View style={styles.reasons}>
        {candidate.reasons.map((reason) => (
          <Badge key={reason.code} label={reason.label} color={colors.accent} background={colors.accentSoft} />
        ))}
      </View>

      <Text style={styles.meta}>
        {item.displayQuantity} {item.displayUnit} · {item.storageLocationLabel}
        {item.expirationIsEstimated ? ' · date estimée' : ''}
      </Text>

      <View style={styles.row}>
        <Button label="Consommé" onPress={onConsumed} disabled={busy} style={styles.rowItem} />
        <Button
          label="Rappeler plus tard"
          variant="secondary"
          onPress={onSnoozed}
          disabled={busy}
          style={styles.rowItem}
        />
      </View>
      <Button label="Jeté" variant="danger" onPress={onDiscarded} disabled={busy} style={styles.spacedSmall} />

      <View style={styles.secondaryRow}>
        {'freezer' !== item.storageLocation ? (
          <Button label="Congeler" variant="ghost" onPress={onFrozen} disabled={busy} />
        ) : null}
        <Button label="Vérifier l'état" variant="ghost" onPress={onCheckDetail} disabled={busy} />
      </View>
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
  row: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.lg },
  rowItem: { flex: 1 },
  spaced: { marginTop: spacing.md },
  spacedSmall: { marginTop: spacing.sm },
  secondaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginTop: spacing.sm,
  },
});
