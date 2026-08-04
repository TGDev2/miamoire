import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { Animated, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../../src/api/client';
import type { MealPlanEntry, MealPlanOverview, SaveTodayCandidate } from '../../src/api/types';
import {
  Button,
  Card,
  EmptyState,
  ErrorBanner,
  Icon,
  Loader,
  Row,
  ScreenHeader,
  SectionTitle,
  Stamp,
  StampHero,
  formatShortDate,
  useTopInset,
} from '../../src/components/ui';
import { deadlineOf } from '../../src/deadline';
import { useEnterAnimation, useReducedMotion } from '../../src/motion';
import { resyncExpiryAlerts } from '../../src/notifications/useExpiryAlerts';
import { colors, deadlineSentence, spacing, text, thermalFor } from '../../src/theme';

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
  const reducedMotion = useReducedMotion();
  const topInset = useTopInset();

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
    <ScrollView style={topInset} contentContainerStyle={styles.container}>
      <ScreenHeader eyebrow={todayLabel()} title="Aujourd'hui" />

      <ErrorBanner message={actionError} />

      {attentionLoading ? (
        <Loader label="Recherche de ce qui réclame votre attention" />
      ) : (
        <>
          {urgent.status === 'error' ? <ErrorBanner message={urgent.message} /> : null}

          {urgentItems.length > 0 ? <SectionTitle>À traiter maintenant</SectionTitle> : null}
          {urgentItems.map((candidate, index) => (
            <TodayCard
              key={candidate.stockItem.id}
              candidate={candidate}
              hero={0 === index}
              index={index}
              animate={!reducedMotion}
              busy={busy}
              onOpen={() => router.push(`/item/${candidate.stockItem.id}`)}
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
            />
          ))}

          {urgentItems.length > 1 ? (
            <Button
              label="Cuisiner avec ces aliments"
              variant="secondary"
              icon="pot-steam-outline"
              onPress={findAMeal}
              hint="Impose les aliments urgents aux recettes proposées"
              style={styles.spaced}
            />
          ) : null}

          {mealPlan.status === 'error' ? <ErrorBanner message={mealPlan.message} /> : null}
          {review.status === 'error' ? <ErrorBanner message={review.message} /> : null}

          {readyEntry || reviewCount > 0 ? <SectionTitle>Déjà prêt</SectionTitle> : null}

          {readyEntry ? (
            <Card style={styles.compactCard}>
              <Text style={text.label}>
                {readyEntry.mealSlotLabel} · {formatShortDate(readyEntry.date)}
              </Text>
              <Text style={[text.heading, styles.compactTitle]}>{readyEntry.displayName}</Text>
              <Text style={[text.small, styles.compactHint]}>
                Tous les ingrédients sont dans votre stock.
              </Text>
              <Button label="Cuisiner ce repas" onPress={() => cookEntry(readyEntry)} style={styles.spaced} />
            </Card>
          ) : null}

          {reviewCount > 0 ? (
            <Card style={styles.compactCard}>
              <Text style={text.label}>File de vérification</Text>
              <Text style={[text.heading, styles.compactTitle]}>
                {reviewCount} produit{reviewCount > 1 ? 's' : ''} à confirmer
              </Text>
              <Text style={[text.small, styles.compactHint]}>
                Quantité entamée, date estimée, lot oublié : quelques secondes pour remettre le stock d'aplomb.
              </Text>
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
              icon="check-decagram-outline"
              title="Rien ne presse"
              description="Aucun aliment ne réclame de décision aujourd'hui. C'est le bon moment pour préparer la suite."
            />
          ) : null}
        </>
      )}

      <SectionTitle>Gestes fréquents</SectionTitle>
      <Card style={styles.rowCard}>
        <Row
          icon="silverware-fork-knife"
          label="J'ai utilisé quelque chose"
          subtitle="Retirer une quantité du stock"
          onPress={() => router.push('/quick-consume')}
        />
        <Row
          icon="barcode-scan"
          label="Enregistrer un retour de courses"
          subtitle="Scanner ce que vous venez d'acheter"
          onPress={() => router.push('/scan')}
        />
        <Row
          icon="format-list-checks"
          label="Liste de courses"
          subtitle="Voir et compléter votre liste"
          onPress={() => router.push('/shopping-list')}
          last
        />
      </Card>

      <SectionTitle>Préparer la suite</SectionTitle>
      <Card style={styles.rowCard}>
        <Row
          icon="calendar-blank-outline"
          label="Mon plan de repas"
          subtitle={nextEntry ? `${nextEntry.displayName} · ${formatShortDate(nextEntry.date)}` : 'Aucun repas planifié'}
          onPress={() => router.push('/meal-plan')}
        />
        <Row
          icon="cart-outline"
          label="Vérifier un achat avant les courses"
          subtitle="Ce qu'il vaut mieux ne pas racheter"
          onPress={() => router.push('/shopping-advisor')}
          last
        />
      </Card>
    </ScrollView>
  );
}

/** « mardi 4 août » — la rubrique de l'écran porte une vraie donnée, pas un mot de remplissage. */
function todayLabel(): string {
  return new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
}

function TodayCard({
  candidate,
  hero,
  index,
  animate,
  busy,
  onOpen,
  onConsumed,
  onSnoozed,
  onDiscarded,
  onFrozen,
}: {
  candidate: SaveTodayCandidate;
  /** Le premier lot est le plus pressant : il porte le cachet en grand. */
  hero: boolean;
  index: number;
  animate: boolean;
  busy: boolean;
  onOpen: () => void;
  onConsumed: () => void;
  onSnoozed: () => void;
  onDiscarded: () => void;
  onFrozen: () => void;
}) {
  const item = candidate.stockItem;
  const deadline = deadlineOf(item);
  const level = thermalFor(deadline.days);
  const entering = useEnterAnimation(index * 60, animate);

  const reasons = candidate.reasons.map((reason) => reason.label).join(' · ');

  return (
    <Animated.View style={entering}>
      <Card level={level} style={styles.card}>
        <Pressable
          onPress={onOpen}
          accessibilityRole="button"
          accessibilityLabel={`${item.variant.culinaryLabel}, ${item.displayQuantity} ${item.displayUnit}, ${item.storageLocationLabel}. ${deadlineSentence(deadline.days, deadline.estimated)}. ${reasons}`}
          accessibilityHint="Ouvre la fiche de l'aliment"
          style={({ pressed }) => [styles.cardHead, pressed && styles.cardHeadPressed]}
        >
          {hero ? (
            <>
              <StampHero days={deadline.days} date={deadline.date} estimated={deadline.estimated} />
              <View style={styles.heroName}>
                <Text style={[text.title, styles.heroNameText]}>{item.variant.culinaryLabel}</Text>
                <Icon name="chevron-right" size={20} color={colors.border} />
              </View>
            </>
          ) : (
            <View style={styles.compactHead}>
              <Text style={[text.heading, styles.compactHeadName]}>{item.variant.culinaryLabel}</Text>
              <Stamp days={deadline.days} date={deadline.date} estimated={deadline.estimated} decorative />
            </View>
          )}

          <Text style={[text.data, styles.meta]}>
            {item.displayQuantity} {item.displayUnit} · {item.storageLocationLabel}
            {item.variant.brand ? ` · ${item.variant.brand}` : ''}
          </Text>
          <Text style={[text.label, styles.reasons]}>{reasons}</Text>
        </Pressable>

        <View style={styles.actions}>
          <Button label="J'ai tout consommé" onPress={onConsumed} disabled={busy} style={styles.actionMain} />
          <Button label="Jeté" variant="danger" onPress={onDiscarded} disabled={busy} style={styles.actionSide} />
        </View>
        <View style={styles.minorActions}>
          <Button label="Rappeler plus tard" variant="ghost" onPress={onSnoozed} disabled={busy} />
          {'freezer' !== item.storageLocation ? (
            <Button label="Congelé" variant="ghost" onPress={onFrozen} disabled={busy} />
          ) : null}
        </View>
      </Card>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  // `paddingLeft` explicitement remis à zéro : `Card` en pose un pour dégager
  // le rail, et Yoga le fait gagner sur le `padding: 0` qui suit. Ici, ce sont
  // les blocs internes qui portent leur propre marge.
  card: { marginBottom: spacing.md, padding: 0, paddingLeft: 0 },
  cardHead: { padding: spacing.lg, paddingLeft: spacing.lg + spacing.xs, paddingBottom: spacing.md },
  cardHeadPressed: { backgroundColor: colors.surfaceSunk },
  heroName: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.lg },
  heroNameText: { flex: 1 },
  compactHead: { flexDirection: 'row', alignItems: 'center', gap: spacing.md },
  compactHeadName: { flex: 1 },
  meta: { marginTop: spacing.sm },
  reasons: { marginTop: spacing.sm },
  actions: {
    flexDirection: 'row',
    gap: spacing.sm,
    paddingHorizontal: spacing.lg,
    paddingLeft: spacing.lg + spacing.xs,
  },
  actionMain: { flex: 1 },
  actionSide: { minWidth: 88 },
  minorActions: {
    flexDirection: 'row',
    gap: spacing.xs,
    paddingHorizontal: spacing.md,
    paddingLeft: spacing.md + spacing.xs,
    paddingBottom: spacing.sm,
  },
  compactCard: { marginBottom: spacing.md },
  compactTitle: { marginTop: spacing.sm },
  compactHint: { marginTop: spacing.xs },
  rowCard: { padding: 0 },
  spaced: { marginTop: spacing.sm, marginBottom: spacing.md },
});
