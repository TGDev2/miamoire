import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../../src/api/client';
import type { MealPlanEntry, MealPlanOverview, SaveTodayCandidate } from '../../src/api/types';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader, SectionTitle } from '../../src/components/ui';
import { resyncExpiryAlerts } from '../../src/notifications/useExpiryAlerts';
import { colors, spacing } from '../../src/theme';

type LoadState<T> =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; value: T };

function errorMessage(exception: unknown): string {
  return exception instanceof Error ? exception.message : 'Chargement impossible.';
}

/**
 * « Qu'est-ce que je dois faire maintenant ? » — l'écran d'ouverture de
 * l'application, organisé en trois blocs volontairement courts : ce qui
 * mérite une action tout de suite, les gestes les plus fréquents, et de quoi
 * préparer la décision suivante. Chaque bloc s'appuie sur des écrans et
 * services déjà existants ; celui-ci ne fait que sélectionner et orienter.
 */
export default function TodayScreen() {
  const router = useRouter();

  const [urgent, setUrgent] = useState<LoadState<SaveTodayCandidate[]>>({ status: 'loading' });
  const [review, setReview] = useState<LoadState<number>>({ status: 'loading' });
  const [mealPlan, setMealPlan] = useState<LoadState<MealPlanOverview>>({ status: 'loading' });
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

  // Le stock et le plan de repas changent depuis d'autres écrans, voire
  // d'autres téléphones du foyer : on recharge à chaque retour sur l'onglet.
  // Les trois signaux sont indépendants : l'échec de l'un ne doit ni bloquer
  // les deux autres, ni se lire comme « rien à faire » (leçon nº 27).
  useFocusEffect(
    useCallback(() => {
      setUrgent({ status: 'loading' });
      setReview({ status: 'loading' });
      setMealPlan({ status: 'loading' });

      void resyncExpiryAlerts();

      void api
        .saveToday()
        .then((response) => setUrgent({ status: 'ready', value: response.items }))
        .catch((exception) => setUrgent({ status: 'error', message: errorMessage(exception) }));

      void api
        .reviewQueue(10)
        .then((response) => setReview({ status: 'ready', value: response.items.length }))
        .catch((exception) => setReview({ status: 'error', message: errorMessage(exception) }));

      void api
        .mealPlan()
        .then((response) => setMealPlan({ status: 'ready', value: response }))
        .catch((exception) => setMealPlan({ status: 'error', message: errorMessage(exception) }));
    }, []),
  );

  function removeUrgentItem(itemId: string) {
    setUrgent((current) =>
      current.status === 'ready'
        ? { status: 'ready', value: current.value.filter((candidate) => candidate.stockItem.id !== itemId) }
        : current,
    );
  }

  async function run(itemId: string, actionId: string, action: (operationKey: string) => Promise<unknown>) {
    setActionError(null);
    setBusy(true);
    const operationKey = operationKeyFor(itemId, actionId);

    try {
      await action(operationKey);
      operationKeys.current.delete(`${itemId}:${actionId}`);
      removeUrgentItem(itemId);
    } catch (exception) {
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKeys.current.delete(`${itemId}:${actionId}`);
      }
      setActionError(errorMessage(exception));
    } finally {
      setBusy(false);
    }
  }

  const urgentItems = urgent.status === 'ready' ? urgent.value : [];
  const reviewCount = review.status === 'ready' ? review.value : 0;
  const readyEntry =
    mealPlan.status === 'ready' ? mealPlan.value.entries.find((entry) => entry.plan?.status === 'ready') ?? null : null;
  const nextEntry = mealPlan.status === 'ready' ? mealPlan.value.entries[0] ?? null : null;

  const attentionLoading = urgent.status === 'loading' || review.status === 'loading' || mealPlan.status === 'loading';
  const hasSomethingToShow = urgentItems.length > 0 || reviewCount > 0 || readyEntry !== null;

  /**
   * Ouvre Cuisiner avec les aliments à sauver déjà imposés, plutôt que de
   * générer directement avec un nombre de convives et des réglages devinés :
   * l'utilisateur y retrouve ses réglages habituels et peut ajuster la sélection.
   */
  function findAMeal() {
    if (urgentItems.length === 0) return;

    const priorityKeys = Array.from(new Set(urgentItems.map((candidate) => candidate.stockItem.variant.key))).slice(0, 3);
    router.push({ pathname: '/cook', params: { priorityKeys: priorityKeys.join(',') } });
  }

  function cookEntry(entry: MealPlanEntry) {
    if ('saved_recipe' === entry.kind && null !== entry.savedRecipeId) {
      router.push({ pathname: '/saved/[id]', params: { id: entry.savedRecipeId, mealPlanEntryId: entry.id } });
    } else if ('snapshot' === entry.kind) {
      router.push(`/meal-plan-entry/${entry.id}`);
    }
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <SectionTitle>Ce qui mérite une action</SectionTitle>
      <ErrorBanner message={actionError} />

      {attentionLoading ? (
        <Loader label="Recherche de ce qui mérite votre attention…" />
      ) : (
        <>
          {urgent.status === 'error' ? <ErrorBanner message={urgent.message} /> : null}
          {urgentItems.map((candidate) => (
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

          {mealPlan.status === 'error' ? <ErrorBanner message={mealPlan.message} /> : null}
          {readyEntry ? (
            <Card style={styles.compactCard}>
              <Text style={styles.compactTitle}>Prêt à cuisiner : {readyEntry.displayName}</Text>
              <Text style={styles.compactHint}>
                {readyEntry.mealSlotLabel} · {formatDisplayDate(readyEntry.date)} — tous les ingrédients sont disponibles.
              </Text>
              <Button
                label="Cuisiner maintenant"
                onPress={() => cookEntry(readyEntry)}
                style={styles.spaced}
              />
            </Card>
          ) : null}

          {review.status === 'error' ? <ErrorBanner message={review.message} /> : null}
          {reviewCount > 0 ? (
            <Card style={styles.compactCard}>
              <Text style={styles.compactTitle}>
                {reviewCount} produit{reviewCount > 1 ? 's' : ''} à vérifier
              </Text>
              <Text style={styles.compactHint}>Confirmez en quelques instants ce qui est encore exact.</Text>
              <Button
                label="Vérifier mon stock"
                variant="secondary"
                onPress={() => router.push('/review')}
                style={styles.spaced}
              />
            </Card>
          ) : null}

          {!hasSomethingToShow && urgent.status === 'ready' ? (
            <EmptyState
              title="Rien à traiter pour l'instant"
              description="Votre stock est à jour, rien ne réclame d'action maintenant."
            />
          ) : null}
        </>
      )}

      <SectionTitle>Gestes fréquents</SectionTitle>
      <Card style={styles.shortcutCard}>
        <ShortcutRow
          label="J'ai utilisé quelque chose"
          subtitle="Déclarer une consommation ordinaire"
          onPress={() => router.push('/quick-consume')}
        />
        <ShortcutRow
          label="Enregistrer un retour de courses"
          subtitle="Scanner ce que vous venez d'acheter"
          onPress={() => router.push('/scan')}
        />
        <ShortcutRow
          label="Liste de courses"
          subtitle="Voir et compléter votre liste"
          onPress={() => router.push('/shopping-list')}
          last
        />
      </Card>

      <SectionTitle>Préparer la suite</SectionTitle>
      <Card style={styles.shortcutCard}>
        <ShortcutRow
          label="Cuisiner avec les produits urgents"
          subtitle={
            urgentItems.length > 0
              ? `${urgentItems.length} produit${urgentItems.length > 1 ? 's' : ''} à utiliser en priorité`
              : 'Aucun produit urgent en ce moment'
          }
          onPress={findAMeal}
          disabled={urgentItems.length === 0}
        />
        <ShortcutRow
          label="Mon plan de repas"
          subtitle={nextEntry ? `${nextEntry.displayName} · ${formatDisplayDate(nextEntry.date)}` : 'Aucun repas planifié'}
          onPress={() => router.push('/meal-plan')}
        />
        <ShortcutRow
          label="Vérifier un achat avant les courses"
          subtitle="Ce qu'il vaut mieux ne pas racheter"
          onPress={() => router.push('/shopping-advisor')}
          last
        />
      </Card>
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

/** Une ligne de raccourci : libellé, sous-titre facultatif, chevron — jamais un bouton plein. */
function ShortcutRow({
  label,
  subtitle,
  onPress,
  disabled = false,
  last = false,
}: {
  label: string;
  subtitle?: string;
  onPress: () => void;
  disabled?: boolean;
  last?: boolean;
}) {
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={subtitle}
      accessibilityState={{ disabled }}
      style={({ pressed }) => [
        styles.shortcutRow,
        !last && styles.shortcutRowDivider,
        pressed && !disabled && styles.shortcutRowPressed,
        disabled && styles.shortcutRowDisabled,
      ]}
    >
      <View style={styles.shortcutText}>
        <Text style={styles.shortcutLabel}>{label}</Text>
        {subtitle ? <Text style={styles.shortcutSubtitle}>{subtitle}</Text> : null}
      </View>
      <Text style={styles.shortcutChevron}>›</Text>
    </Pressable>
  );
}

function formatDisplayDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  card: { marginBottom: spacing.lg },
  compactCard: { marginBottom: spacing.lg },
  compactTitle: { fontSize: 16, fontWeight: '700', color: colors.ink },
  compactHint: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.xs },
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
  shortcutCard: { padding: 0, marginBottom: spacing.lg, overflow: 'hidden' },
  shortcutRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md + 2,
  },
  shortcutRowDivider: { borderBottomWidth: 1, borderBottomColor: colors.border },
  shortcutRowPressed: { backgroundColor: colors.surfaceMuted },
  shortcutRowDisabled: { opacity: 0.5 },
  shortcutText: { flex: 1, paddingRight: spacing.md },
  shortcutLabel: { fontSize: 15, fontWeight: '600', color: colors.ink },
  shortcutSubtitle: { fontSize: 13, color: colors.inkMuted, marginTop: 2 },
  shortcutChevron: { fontSize: 20, color: colors.inkMuted },
});
