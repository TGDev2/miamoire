import { useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import type { ReviewCandidate, StockItem } from '../src/api/types';
import { useFeedback } from '../src/components/Feedback';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader, Stamp } from '../src/components/ui';
import { deadlineOf } from '../src/deadline';
import * as haptics from '../src/haptics';
import { useSession } from '../src/session/SessionContext';
import { colors, deadlineSentence, radius, spacing, text, thermalFor } from '../src/theme';

/**
 * Une session courte : la file entière est chargée une seule fois, puis on
 * avance localement lot par lot. Une carte résolue ne revient jamais — que ce
 * soit par une action, un passage, ou une catégorie mise en sourdine — d'où un
 * état « file restante » plutôt qu'un simple index dans une liste figée.
 */
export default function ReviewScreen() {
  const router = useRouter();
  const { household, refresh } = useSession();

  const { commitWithUndo } = useFeedback();

  const [initialCount, setInitialCount] = useState<number | null>(null);
  /**
   * Les lots réellement tranchés, comptés un par un.
   *
   * Il se déduisait de la longueur restante de la file — jusqu'à ce qu'une mise
   * en sourdine en retire cinq d'un coup : la barre bondissait et le compteur
   * sautait de 2/10 à 7/10 sans qu'on ait vérifié quoi que ce soit. Mettre une
   * catégorie en sourdine raccourcit la file, ce n'est pas la faire avancer.
   */
  const [resolved, setResolved] = useState(0);
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
    setResolved((current) => current + 1);
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

  /**
   * Mettre une catégorie en sourdine était irréversible, sans confirmation, et
   * à un pouce du bouton « Passer ». Le réglage vit dans le foyer, et rien sur
   * cet écran ne permettait d'y revenir.
   */
  function muteCategory(category: string) {
    if (!household) return;

    setActionError(null);
    haptics.removed();

    const previous = queue;
    const previousCount = initialCount ?? queue.length;
    const kept = queue.filter((candidate) => candidate.stockItem.variant.category !== category);

    setQueue(kept);
    // La file raccourcit, elle n'avance pas : le total baisse d'autant.
    setInitialCount(previousCount - (queue.length - kept.length));

    commitWithUndo({
      message: `« ${category} » ne sera plus proposé à la vérification`,
      commit: async () => {
        await api.updateHousehold(household.id, {
          mutedReviewCategories: [...household.mutedReviewCategories, category],
        });
        await refresh();
      },
      onUndo: () => {
        setQueue(previous);
        setInitialCount(previousCount);
      },
      onFailed: (message) => {
        setQueue(previous);
        setInitialCount(previousCount);
        setActionError(message);
      },
    });
  }

  function goToDetail(itemId: string) {
    advance();
    router.push(`/item/${itemId}`);
  }

  if (null === initialCount && !loadError) {
    return <Loader label="Recherche des lots à vérifier" />;
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
          icon="check-decagram-outline"
          title="Stock à jour"
          description={
            0 === initialCount
              ? 'Aucun lot ne mérite une vérification pour le moment.'
              : 'Vous avez fait le tour des lots à vérifier.'
          }
          action={<Button label="Retour au stock" variant="secondary" onPress={() => router.back()} />}
        />
      </View>
    );
  }

  const current = queue[0];
  const item = current.stockItem;
  const deadline = deadlineOf(item);
  const resolvedCount = Math.min(resolved, initialCount ?? resolved);
  const position = resolvedCount + 1;

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      {/* La file a une longueur connue : la montrer transforme une corvée sans
          fin en une tâche qui se termine. */}
      <View
        style={styles.progress}
        accessible
        accessibilityLabel={`Lot ${position} sur ${initialCount}`}
      >
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { flex: resolvedCount }]} />
          <View style={{ flex: (initialCount ?? 1) - resolvedCount }} />
        </View>
        <Text style={text.label}>
          {position} / {initialCount}
        </Text>
      </View>

      <ErrorBanner message={actionError} />

      <Card level={thermalFor(deadline.days)}>
        <View style={styles.headerRow}>
          <View style={styles.flex}>
            <Text style={text.title}>{item.variant.culinaryLabel}</Text>
            {item.variant.brand ? (
              <Text style={[text.small, styles.brand]}>{item.variant.brand}</Text>
            ) : null}
          </View>
          <Stamp days={deadline.days} date={deadline.date} estimated={deadline.estimated} />
        </View>

        <View style={styles.reasons}>
          {current.reasons.map((reason) => (
            <Badge key={reason.code} label={reason.label} level="soon" />
          ))}
        </View>

        <Text style={[text.heading, styles.question]}>{questionFor(item)}</Text>
        {/* Une phrase, donc la voix humaine — le cachet, juste au-dessus, dit
            déjà la même échéance dans la voix machine. */}
        <Text style={[text.small, styles.meta]}>
          {item.storageLocationLabel} · {deadlineSentence(deadline.days, deadline.estimated).toLowerCase()}
        </Text>
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
        label="Terminé, il n'en reste rien"
        variant="secondary"
        onPress={() => run(item.id, 'finished', (operationKey) => api.consume(item.id, { mode: 'all' }, operationKey))}
        disabled={busy}
        style={styles.spaced}
      />

      {/* « Passer » était l'élément le moins visible de l'écran, alors que c'est
          l'issue la plus légitime pour quelqu'un d'incertain — précisément
          l'état de qui ouvre une file de vérification. */}
      <View style={styles.minorRow}>
        <Button
          label="Passer"
          variant="secondary"
          icon="chevron-right"
          onPress={advance}
          disabled={busy}
          hint="Reviendra à la prochaine session"
          style={styles.minorItem}
        />
        <Button
          label="Corriger précisément"
          variant="ghost"
          onPress={() => goToDetail(item.id)}
          disabled={busy}
          style={styles.minorItem}
        />
      </View>

      {item.variant.category ? (
        <Button
          label={`Ne plus vérifier « ${item.variant.category} »`}
          variant="ghost"
          onPress={() => muteCategory(item.variant.category as string)}
          disabled={busy}
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
  flex: { flex: 1 },
  progress: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.lg },
  progressTrack: {
    flex: 1,
    flexDirection: 'row',
    height: 3,
    borderRadius: radius.pill,
    backgroundColor: colors.border,
    overflow: 'hidden',
  },
  progressFill: { backgroundColor: colors.ink },
  headerRow: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  brand: { marginTop: 2 },
  reasons: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.md },
  question: { marginTop: spacing.lg },
  meta: { marginTop: spacing.sm },
  spaced: { marginTop: spacing.md },
  row: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
  rowItem: { flex: 1 },
  minorRow: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
  minorItem: { flex: 1 },
});
