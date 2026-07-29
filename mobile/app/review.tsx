import { useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import type { ReviewCandidate, StockItem } from '../src/api/types';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader } from '../src/components/ui';
import { useSession } from '../src/session/SessionContext';
import { colors, spacing } from '../src/theme';

/**
 * Une session courte : la file entière est chargée une seule fois, puis on
 * avance localement lot par lot. Une carte résolue ne revient jamais — que ce
 * soit par une action, un passage, ou une catégorie mise en sourdine — d'où un
 * état « file restante » plutôt qu'un simple index dans une liste figée.
 */
export default function ReviewScreen() {
  const router = useRouter();
  const { household, refresh } = useSession();

  const [initialCount, setInitialCount] = useState<number | null>(null);
  const [queue, setQueue] = useState<ReviewCandidate[]>([]);
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

  useEffect(() => {
    let cancelled = false;

    api
      .reviewQueue()
      .then((response) => {
        if (cancelled) return;
        setQueue(response.items);
        setInitialCount(response.items.length);
      })
      .catch((exception) => {
        if (cancelled) return;
        setLoadError(exception instanceof Error ? exception.message : 'Chargement impossible.');
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const advance = useCallback(() => {
    setQueue((current) => current.slice(1));
  }, []);

  /**
   * Contrairement à `item/[id].tsx`, un 409 ici n'arrête pas la session : ce
   * lot a changé entre-temps (une autre personne du foyer vient d'agir
   * dessus), donc on passe au suivant plutôt que de bloquer une session
   * censée durer moins d'une minute.
   */
  async function run(itemId: string, actionId: string, action: (operationKey: string) => Promise<unknown>) {
    setActionError(null);
    setBusy(true);
    const operationKey = operationKeyFor(itemId, actionId);

    try {
      await action(operationKey);
      operationKeys.current.delete(`${itemId}:${actionId}`);
      advance();
    } catch (exception) {
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKeys.current.delete(`${itemId}:${actionId}`);
      }

      if (exception instanceof ApiError && exception.status === 409) {
        advance();
      } else {
        setActionError(exception instanceof Error ? exception.message : 'Action impossible.');
      }
    } finally {
      setBusy(false);
    }
  }

  async function muteCategory(category: string) {
    if (!household) return;

    setActionError(null);
    setBusy(true);

    try {
      await api.updateHousehold(household.id, {
        mutedReviewCategories: [...household.mutedReviewCategories, category],
      });
      await refresh();
      setQueue((current) => current.filter((candidate) => candidate.stockItem.variant.category !== category));
    } catch (exception) {
      setActionError(exception instanceof Error ? exception.message : 'Action impossible.');
    } finally {
      setBusy(false);
    }
  }

  function goToDetail(itemId: string) {
    advance();
    router.push(`/item/${itemId}`);
  }

  if (null === initialCount && !loadError) {
    return <Loader label="Recherche des lots à vérifier…" />;
  }

  if (loadError) {
    return (
      <View style={styles.container}>
        <ErrorBanner message={loadError} />
      </View>
    );
  }

  if (0 === queue.length) {
    return (
      <View style={styles.container}>
        <EmptyState
          title="Stock à jour"
          description={
            0 === initialCount
              ? 'Aucun lot ne mérite une vérification pour le moment.'
              : 'Vous avez fait le tour des lots à vérifier.'
          }
        />
        <Button label="Retour au stock" variant="secondary" onPress={() => router.back()} />
      </View>
    );
  }

  const current = queue[0];
  const item = current.stockItem;
  const resolvedCount = (initialCount ?? queue.length) - queue.length;

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <Text style={styles.progress}>
        Lot {resolvedCount + 1} sur {initialCount}
      </Text>

      <ErrorBanner message={actionError} />

      <Card>
        <Text style={styles.name}>{item.variant.culinaryLabel}</Text>
        {item.variant.brand ? <Text style={styles.brand}>{item.variant.brand}</Text> : null}

        <View style={styles.reasons}>
          {current.reasons.map((reason) => (
            <Badge key={reason.code} label={reason.label} color={colors.info} background={colors.infoSoft} />
          ))}
        </View>

        <Text style={styles.question}>{questionFor(item)}</Text>
        <Text style={styles.meta}>{item.storageLocationLabel}</Text>
      </Card>

      <Button
        label="Oui, toujours là"
        onPress={() => run(item.id, 'confirm', (operationKey) => api.confirmStockItem(item.id, operationKey))}
        disabled={busy}
        style={styles.spaced}
      />

      <View style={styles.row}>
        <Button
          label="Environ la moitié"
          variant="secondary"
          onPress={() =>
            run(item.id, 'half', (operationKey) =>
              api.correct(item.id, { fillLevel: 'half' }, operationKey, item.revision),
            )
          }
          disabled={busy}
          style={styles.rowItem}
        />
        <Button
          label="Presque vide"
          variant="secondary"
          onPress={() =>
            run(item.id, 'almost_empty', (operationKey) =>
              api.correct(item.id, { fillLevel: 'almost_empty' }, operationKey, item.revision),
            )
          }
          disabled={busy}
          style={styles.rowItem}
        />
      </View>

      <Button
        label="Terminé"
        variant="secondary"
        onPress={() => run(item.id, 'finished', (operationKey) => api.consume(item.id, { mode: 'all' }, operationKey))}
        disabled={busy}
        style={styles.spaced}
      />

      <Button
        label="Corriger précisément"
        variant="ghost"
        onPress={() => goToDetail(item.id)}
        disabled={busy}
        style={styles.spaced}
      />

      <Button label="Passer" variant="ghost" onPress={advance} disabled={busy} />

      {item.variant.category ? (
        <Button
          label={`Ne plus vérifier « ${item.variant.category} »`}
          variant="ghost"
          onPress={() => muteCategory(item.variant.category as string)}
          disabled={busy}
          style={styles.spaced}
        />
      ) : null}
    </ScrollView>
  );
}

/** Un seul gabarit pour tous les motifs : les badges donnent le contexte, pas la question. */
function questionFor(item: StockItem): string {
  const quantity = `${item.displayQuantity} ${item.displayUnit}`;

  return item.quantityIsEstimated
    ? `Il reste toujours environ ${quantity} de ${item.variant.culinaryLabel} ?`
    : `Il reste toujours ${quantity} de ${item.variant.culinaryLabel} ?`;
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  progress: {
    fontSize: 13,
    fontWeight: '600',
    color: colors.inkMuted,
    textAlign: 'center',
    marginBottom: spacing.md,
  },
  name: { fontSize: 20, fontWeight: '800', color: colors.ink },
  brand: { fontSize: 14, color: colors.inkMuted, marginTop: 2 },
  reasons: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.md },
  question: { fontSize: 17, fontWeight: '600', color: colors.ink, marginTop: spacing.lg, lineHeight: 24 },
  meta: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.sm },
  spaced: { marginTop: spacing.md },
  row: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.md },
  rowItem: { flex: 1 },
});
