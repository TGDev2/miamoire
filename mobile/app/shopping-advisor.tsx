import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';

import { api } from '../src/api/client';
import type { ProductVariant, ShoppingAdvisorCandidate } from '../src/api/types';
import { Badge, Button, Card, EmptyState, ErrorBanner, Loader } from '../src/components/ui';
import { colors, shoppingAdviceKindPresentation, spacing } from '../src/theme';

/**
 * « Qu'est-ce que je ne dois surtout pas racheter ? » — avant les courses, pas
 * après (Évolution 3, `tasks/evolutions.md`). Chaque carte porte son
 * raisonnement et sa confiance : l'utilisateur reste seul décisionnaire, il
 * accepte, ignore ou désactive.
 */
export default function ShoppingAdvisorScreen() {
  const router = useRouter();

  const [items, setItems] = useState<ShoppingAdvisorCandidate[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const [muted, setMuted] = useState<ProductVariant[] | null>(null);
  const [showMuted, setShowMuted] = useState(false);

  useFocusEffect(
    useCallback(() => {
      setLoadError(null);
      void api
        .shoppingAdvisor()
        .then((response) => setItems(response.items))
        .catch((exception) => {
          setLoadError(exception instanceof Error ? exception.message : 'Chargement impossible.');
        });
      void api
        .mutedShoppingAdvice()
        .then((response) => setMuted(response.items))
        .catch(() => undefined);
    }, []),
  );

  function removeCandidate(variantId: string) {
    setItems((current) => (current ?? []).filter((candidate) => candidate.variant.id !== variantId));
  }

  async function run(variantId: string, action: () => Promise<unknown>) {
    setActionError(null);
    setBusyId(variantId);
    try {
      await action();
    } catch (exception) {
      setActionError(exception instanceof Error ? exception.message : 'Action impossible.');
    } finally {
      setBusyId(null);
    }
  }

  /**
   * Suivre le conseil, quel qu'il soit, revient à réconcilier la liste de
   * courses avec lui : l'ajouter quand il manque, la retirer quand elle n'a
   * plus lieu d'être.
   */
  function accept(candidate: ShoppingAdvisorCandidate) {
    void run(candidate.variant.id, async () => {
      if ('running_out' === candidate.kind) {
        await api.addShoppingListItems([
          { label: candidate.variant.displayName, unit: candidate.variant.usualPackageUnit ?? 'piece' },
        ]);
      } else if (candidate.existingShoppingListItemId) {
        await api.deleteShoppingListItem(candidate.existingShoppingListItemId);
      }
      removeCandidate(candidate.variant.id);
    });
  }

  /** Purement local : la suggestion réapparaîtra à la prochaine visite si les données n'ont pas changé. */
  function ignore(candidate: ShoppingAdvisorCandidate) {
    removeCandidate(candidate.variant.id);
  }

  function disable(candidate: ShoppingAdvisorCandidate) {
    void run(candidate.variant.id, async () => {
      await api.muteShoppingAdvice(candidate.variant.id);
      removeCandidate(candidate.variant.id);
      setMuted((current) => [candidate.variant, ...(current ?? [])]);
    });
  }

  function reactivate(variant: ProductVariant) {
    void run(variant.id, async () => {
      await api.unmuteShoppingAdvice(variant.id);
      setMuted((current) => (current ?? []).filter((item) => item.id !== variant.id));
    });
  }

  let body: React.ReactNode;

  if (null === items && !loadError) {
    body = <Loader label="Recherche de ce qui ne vaut pas la peine d'être racheté…" />;
  } else if (loadError) {
    body = (
      <View style={styles.container}>
        <ErrorBanner message={loadError} />
      </View>
    );
  } else if (0 === items?.length) {
    body = (
      <View style={styles.container}>
        <EmptyState
          title="Rien à signaler"
          description="Ni le stock actuel, ni l'historique, ni les repas prévus ne justifient une suggestion pour l'instant."
        />
      </View>
    );
  } else {
    body = (
      <ScrollView contentContainerStyle={styles.container}>
        <ErrorBanner message={actionError} />

        {(items ?? []).map((candidate) => (
          <AdvisorCard
            key={candidate.variant.id}
            candidate={candidate}
            busy={busyId === candidate.variant.id}
            onAccept={() => accept(candidate)}
            onIgnore={() => ignore(candidate)}
            onDisable={() => disable(candidate)}
          />
        ))}
      </ScrollView>
    );
  }

  return (
    <View style={styles.screen}>
      <View style={styles.entryPoint}>
        <Button
          label="Vérifier un produit en magasin"
          variant="secondary"
          onPress={() => router.push('/shopping-check')}
        />
      </View>

      {body}

      {muted && muted.length > 0 ? (
        <View style={styles.mutedSection}>
          <Button
            label={`Suggestions désactivées (${muted.length})`}
            variant="ghost"
            onPress={() => setShowMuted((current) => !current)}
          />
          {showMuted ? (
            <View style={styles.mutedList}>
              {muted.map((variant) => (
                <View key={variant.id} style={styles.mutedRow}>
                  <Text style={styles.mutedLabel}>{variant.displayName}</Text>
                  <Button
                    label="Réactiver"
                    variant="ghost"
                    onPress={() => reactivate(variant)}
                    disabled={busyId === variant.id}
                  />
                </View>
              ))}
            </View>
          ) : null}
        </View>
      ) : null}
    </View>
  );
}

function AdvisorCard({
  candidate,
  busy,
  onAccept,
  onIgnore,
  onDisable,
}: {
  candidate: ShoppingAdvisorCandidate;
  busy: boolean;
  onAccept: () => void;
  onIgnore: () => void;
  onDisable: () => void;
}) {
  const presentation = shoppingAdviceKindPresentation[candidate.kind] ?? {
    color: colors.ink,
    background: colors.surfaceMuted,
  };

  const canAccept = 'running_out' === candidate.kind || null !== candidate.existingShoppingListItemId;
  const acceptLabel = 'running_out' === candidate.kind ? 'Ajouter à la liste' : 'Retirer de ma liste';

  return (
    <Card style={styles.card}>
      <Text style={styles.headline}>{candidate.headline}</Text>

      <View style={styles.badges}>
        <Badge label={candidate.kindLabel} color={presentation.color} background={presentation.background} />
        <Badge label={candidate.confidenceLabel} color={colors.info} background={colors.infoSoft} />
      </View>

      <Text style={styles.reasoning}>{candidate.reasoning}</Text>

      <View style={styles.row}>
        {canAccept ? (
          <Button label={acceptLabel} onPress={onAccept} disabled={busy} style={styles.rowItem} />
        ) : null}
        <Button label="Ignorer" variant="secondary" onPress={onIgnore} disabled={busy} style={styles.rowItem} />
      </View>
      <Button
        label="Ne plus suggérer"
        variant="ghost"
        onPress={onDisable}
        disabled={busy}
        style={styles.spacedSmall}
      />
    </Card>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1 },
  entryPoint: { paddingHorizontal: spacing.xl, paddingTop: spacing.xl },
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2, flexGrow: 1 },
  card: { marginBottom: spacing.lg },
  headline: { fontSize: 17, fontWeight: '800', color: colors.ink },
  badges: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs, marginTop: spacing.md },
  reasoning: { fontSize: 14, color: colors.inkMuted, marginTop: spacing.md, lineHeight: 20 },
  row: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.lg },
  rowItem: { flex: 1 },
  spacedSmall: { marginTop: spacing.sm },
  mutedSection: { paddingHorizontal: spacing.xl, paddingBottom: spacing.xl },
  mutedList: { marginTop: spacing.sm },
  mutedRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.border,
  },
  mutedLabel: { fontSize: 14, color: colors.ink },
});
