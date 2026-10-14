import React, { useEffect, useRef, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import type { CutQueueItem, ProductCutValue, Reference } from '../src/api/types';
import { CutPicker } from '../src/components/CutPicker';
import { Card, EmptyState, ErrorBanner, Loader } from '../src/components/ui';
import * as haptics from '../src/haptics';
import { spacing, text } from '../src/theme';

/**
 * The catch-up list for stock put away before the cut could be given.
 *
 * One card per food, one tap per card: the cut belongs to the food, so it
 * answers for all its lots and is remembered for the next purchases. A food
 * leaves the list as soon as its cut is saved.
 */
export default function CutsScreen() {
  const [reference, setReference] = useState<Reference | null>(null);
  const [queue, setQueue] = useState<CutQueueItem[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<string | null>(null);

  // One key per attempt, kept while the network is down so a retry is not a second write.
  const operationKeys = useRef(new Map<string, string>());

  useEffect(() => {
    let cancelled = false;

    Promise.all([api.reference(), api.cutQueue()])
      .then(([ref, response]) => {
        if (cancelled) return;
        setReference(ref);
        setQueue(response.items);
      })
      .catch((exception) => {
        if (!cancelled) setLoadError(exception instanceof Error ? exception.message : 'Chargement impossible.');
      });

    return () => {
      cancelled = true;
    };
  }, []);

  async function save(entry: CutQueueItem, cut: ProductCutValue | null) {
    if (null === cut || null !== saving) return;

    const mapKey = `${entry.stockItemId}:${cut}`;
    const operationKey = operationKeys.current.get(mapKey) ?? generateOperationKey();
    operationKeys.current.set(mapKey, operationKey);

    setSaving(entry.variant.id);
    setErrors((current) => ({ ...current, [entry.variant.id]: '' }));

    try {
      await api.updateStockItem(entry.stockItemId, { cut }, operationKey);
      operationKeys.current.delete(mapKey);
      haptics.success();
      setQueue((current) => (current ?? []).filter((candidate) => candidate.variant.id !== entry.variant.id));
    } catch (exception) {
      if (!(exception instanceof ApiError) || 0 !== exception.status) operationKeys.current.delete(mapKey);
      setErrors((current) => ({
        ...current,
        [entry.variant.id]: exception instanceof Error ? exception.message : 'Enregistrement impossible.',
      }));
    } finally {
      setSaving(null);
    }
  }

  if (null !== loadError) {
    return (
      <View style={styles.container}>
        <ErrorBanner message={loadError} />
      </View>
    );
  }

  if (null === queue || null === reference) return <Loader />;

  if (0 === queue.length) {
    return (
      <View style={styles.container}>
        <EmptyState
          icon="check-decagram-outline"
          title="Toutes les formes sont connues"
          description="Les recettes n'ont plus de forme à supposer pour ce qui est dans votre stock."
        />
      </View>
    );
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <Text style={[text.body, styles.intro]}>
        La forme décide de la cuisson : une escalope se saisit trois minutes, un blanc entier cuit vingt. Elle
        vaut pour tous les lots de l'aliment, et pour vos prochains achats.
      </Text>

      {queue.map((entry) => {
        const options = reference.productCuts.filter((option) => entry.variant.plausibleCuts.includes(option.value));
        const hint = suggestionHint(entry, reference);

        return (
          <Card key={entry.variant.id} style={styles.card}>
            <Text style={text.heading}>{entry.variant.culinaryLabel}</Text>
            <Text style={[text.small, styles.meta]}>
              {entry.lotCount > 1 ? `${entry.lotCount} lots` : '1 lot'}
              {entry.variant.brand ? ` · ${entry.variant.brand}` : ''}
              {null !== hint ? ` · ${hint}` : ''}
            </Text>
            <ErrorBanner message={errors[entry.variant.id] || null} />
            {saving === entry.variant.id ? (
              <Text style={text.small}>Enregistrement…</Text>
            ) : (
              <CutPicker options={options} value={null} onChange={(cut) => void save(entry, cut)} />
            )}
          </Card>
        );
      })}
    </ScrollView>
  );
}

/** Why a cut is suggested, in the household's words — or nothing when there is no suggestion. */
function suggestionHint(entry: CutQueueItem, reference: Reference): string | null {
  const label = reference.productCuts.find((option) => option.value === entry.suggestedCut)?.label;
  if (undefined === label) return null;

  return 'household' === entry.suggestedCutSource
    ? `d'habitude : ${label.toLowerCase()}`
    : `sans doute : ${label.toLowerCase()}`;
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  intro: { marginBottom: spacing.lg },
  card: { marginBottom: spacing.md },
  meta: { marginTop: spacing.xs, marginBottom: spacing.md },
});
