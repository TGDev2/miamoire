import { useFocusEffect, useRouter } from 'expo-router';
import * as SecureStore from 'expo-secure-store';
import React, { useCallback, useState } from 'react';
import {
  FlatList,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';

import { api } from '../../src/api/client';
import type { Reference, StockItem } from '../../src/api/types';
import { Badge, Button, Chip, EmptyState, ErrorBanner, Loader } from '../../src/components/ui';
import { resyncExpiryAlerts } from '../../src/notifications/useExpiryAlerts';
import { colors, radius, spacing, statusPresentation } from '../../src/theme';

const EXPIRY_FILTER_DAYS = 3;

/** Une fois par semaine au plus : la date de la dernière proposition, qu'elle ait été acceptée ou non. */
const LAST_REVIEW_PROMPT_KEY = 'food.lastReviewPromptAt';
const REVIEW_PROMPT_INTERVAL_DAYS = 7;

/**
 * Faut-il proposer « Vérifier mon stock » cette fois-ci ?
 *
 * Un seul appel réseau, gardé par les 7 jours : contrairement au bouton
 * d'entrée toujours visible, une bannière qui s'affiche sur un stock déjà à
 * jour apprend à l'utilisateur à la fermer par réflexe.
 */
async function shouldOfferReviewNudge(): Promise<boolean> {
  const last = await SecureStore.getItemAsync(LAST_REVIEW_PROMPT_KEY);
  const daysSinceLast = last ? (Date.now() - Number(last)) / 86_400_000 : Infinity;

  if (daysSinceLast < REVIEW_PROMPT_INTERVAL_DAYS) return false;

  await SecureStore.setItemAsync(LAST_REVIEW_PROMPT_KEY, String(Date.now()));

  try {
    const queue = await api.reviewQueue(1);
    return queue.items.length > 0;
  } catch {
    return false;
  }
}

export default function StockScreen() {
  const router = useRouter();

  const [items, setItems] = useState<StockItem[]>([]);
  const [reference, setReference] = useState<Reference | null>(null);
  const [search, setSearch] = useState('');
  const [location, setLocation] = useState<string | null>(null);
  const [category, setCategory] = useState<string | null>(null);
  const [urgentOnly, setUrgentOnly] = useState(false);
  /** Les archives sont un autre inventaire, pas un filtre qui s'ajoute aux autres. */
  const [archived, setArchived] = useState(false);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showReviewNudge, setShowReviewNudge] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const [stock, ref] = await Promise.all([
        api.stockItems({
          search: search || undefined,
          location: location ?? undefined,
          category: category ?? undefined,
          expiringWithinDays: urgentOnly ? EXPIRY_FILTER_DAYS : undefined,
          archived: archived || undefined,
        }),
        api.reference(),
      ]);
      setItems(stock.items);
      setReference(ref);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [search, location, category, urgentOnly, archived]);

  // Le stock change depuis d'autres écrans (scan, consommation, recette) :
  // on recharge à chaque retour sur l'onglet.
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  // Les alertes partent de l'inventaire ENTIER, jamais de la liste affichée : filtrer
  // l'écran ne doit pas annuler les alertes des lots masqués (leçon nº 6). La
  // reprogrammation vit au niveau de l'application ; on n'ajoute ici que le focus
  // de l'onglet, le moment où l'on vient justement regarder son stock.
  useFocusEffect(
    useCallback(() => {
      void resyncExpiryAlerts();
    }, []),
  );

  useFocusEffect(
    useCallback(() => {
      void shouldOfferReviewNudge().then(setShowReviewNudge);
    }, []),
  );

  if (loading) return <Loader label="Chargement du stock…" />;

  const urgentCount = items.filter(
    (item) => item.status === 'expiring_soon' || item.status === 'expired',
  ).length;

  /** Une liste vide ne veut pas dire la même chose selon qu'on filtre ou non. */
  const filtering = Boolean(search) || location !== null || category !== null || urgentOnly;

  return (
    <View style={styles.flex}>
      <View style={styles.toolbar}>
        <TextInput
          value={search}
          onChangeText={setSearch}
          placeholder="Rechercher un aliment…"
          placeholderTextColor={colors.inkMuted}
          style={styles.search}
          returnKeyType="search"
        />

        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filters}>
          <Chip
            label={`Bientôt périmés${urgentCount ? ` (${urgentCount})` : ''}`}
            selected={urgentOnly}
            onPress={() => setUrgentOnly((value) => !value)}
          />
          {/* Les archives n'étaient atteignables par aucun écran : le geste
              s'annonçait réversible sans que rien ne permette de le défaire. */}
          <Chip
            label="Archivés"
            selected={archived}
            onPress={() => setArchived((value) => !value)}
          />
          {reference?.storageLocations.map((option) => (
            <Chip
              key={option.value}
              label={option.label}
              selected={location === option.value}
              onPress={() => setLocation(location === option.value ? null : option.value)}
            />
          ))}
          {reference?.categories.map((name) => (
            <Chip
              key={name}
              label={name}
              selected={category === name}
              onPress={() => setCategory(category === name ? null : name)}
            />
          ))}
        </ScrollView>

        <View style={styles.toolbarButtons}>
          <Button
            label="J'ai utilisé quelque chose"
            onPress={() => router.push('/quick-consume')}
            style={styles.toolbarButton}
          />
          <Button
            label="Vérifier mon stock"
            variant="secondary"
            onPress={() => router.push('/review')}
            style={styles.toolbarButton}
          />
        </View>
      </View>

      {showReviewNudge ? (
        <View style={styles.nudge} accessible accessibilityLabel="Envie de vérifier votre stock ?">
          <Text style={styles.nudgeText}>Envie de vérifier votre stock ?</Text>
          <View style={styles.nudgeActions}>
            <Button
              label="Plus tard"
              variant="ghost"
              onPress={() => setShowReviewNudge(false)}
              style={styles.nudgeButton}
            />
            <Button
              label="Vérifier"
              onPress={() => {
                setShowReviewNudge(false);
                router.push('/review');
              }}
              style={styles.nudgeButton}
            />
          </View>
        </View>
      ) : null}

      <FlatList
        data={items}
        keyExtractor={(item) => item.id}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => {
              setRefreshing(true);
              load();
            }}
            tintColor={colors.primary}
          />
        }
        ListHeaderComponent={<ErrorBanner message={error} />}
        ListEmptyComponent={
          // « Votre stock est vide » sous une recherche active est faux, et c'est
          // la seule lecture qui décourage : on croit avoir perdu son inventaire
          // alors qu'on a simplement tapé un mot qui ne correspond à rien.
          // Les archives ajoutent un troisième cas, qui ne dit pas la même chose
          // que les deux autres : « rien n'a été rangé ici », pas « rien à voir ».
          archived && !filtering ? (
            <EmptyState
              title="Aucun lot archivé"
              description="Archiver un aliment le sort de l'inventaire sans rien lui retirer, et il revient ici en attendant d'être restauré."
            />
          ) : filtering || archived ? (
            <EmptyState
              title="Aucun résultat"
              description={
                search
                  ? `Rien ne correspond à « ${search} » avec les filtres actifs.`
                  : 'Aucun aliment ne correspond aux filtres actifs.'
              }
            />
          ) : (
            <EmptyState
              title="Votre stock est vide"
              description="Scannez un code-barres ou saisissez un aliment manuellement pour commencer."
            />
          )
        }
        renderItem={({ item }) => (
          <StockRow item={item} onPress={() => router.push(`/item/${item.id}`)} />
        )}
      />

      <Pressable
        style={styles.fab}
        onPress={() => router.push('/add')}
        accessibilityRole="button"
        accessibilityLabel="Ajouter un aliment au stock"
      >
        <Text style={styles.fabLabel}>+</Text>
      </Pressable>
    </View>
  );
}

function StockRow({ item, onPress }: { item: StockItem; onPress: () => void }) {
  const presentation = statusPresentation[item.status] ?? statusPresentation.sealed;

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      accessibilityRole="button"
      // Sans cela, le lecteur d'écran énumère cinq textes séparés puis un badge,
      // sans dire qu'ils forment une ligne ni qu'elle s'ouvre.
      accessibilityLabel={`${item.variant.culinaryLabel}, ${item.displayQuantity} ${item.displayUnit}, ${item.storageLocationLabel}, ${presentation.label}. ${formatExpiry(item)}`}
      accessibilityHint="Ouvre la fiche de l'aliment"
    >
      <View style={styles.rowMain}>
        {/* Le libellé culinaire distingue « Tomates » de « Tomates (en conserve) » :
            ce sont deux stocks, et la liste doit le montrer. */}
        <Text style={styles.rowTitle} numberOfLines={1}>
          {item.variant.culinaryLabel}
        </Text>
        {item.variant.brand ? (
          <Text style={styles.rowBrand} numberOfLines={1}>
            {item.variant.brand}
          </Text>
        ) : null}

        <Text style={styles.rowMeta}>
          {item.displayQuantity} {item.displayUnit} · {item.storageLocationLabel}
          {item.quantityIsEstimated ? ' · estimé' : ''}
        </Text>

        <Text style={styles.rowExpiry}>
          {formatExpiry(item)}
          {item.addedBy ? ` · ajouté par ${item.addedBy}` : ''}
        </Text>
      </View>

      <Badge
        label={presentation.label}
        color={presentation.color}
        background={presentation.background}
      />
    </Pressable>
  );
}

/**
 * Une date estimée ne se dit pas comme une date lue.
 *
 * « Périmé depuis 2 jours » sur une salade dont la date a été déduite ferait jeter
 * un aliment parfaitement bon. « À vérifier » demande de regarder, ce qui est
 * exactement ce qu'une estimation permet de conclure.
 */
function formatExpiry(item: StockItem): string {
  if (item.expirationDate === null) return 'Aucune date limite renseignée';

  const days = item.daysUntilExpiration;
  if (days === null) return 'Aucune date limite renseignée';

  if (item.expirationIsEstimated) {
    if (days < 0) return `À vérifier — estimé il y a ${Math.abs(days)} j`;
    if (days === 0) return 'À vérifier aujourd\'hui (estimé)';
    return `À consommer sous ${days} j environ (estimé)`;
  }

  if (days < 0) return `Périmé depuis ${Math.abs(days)} j`;
  if (days === 0) return "Périme aujourd'hui";
  if (days === 1) return 'Périme demain';

  return `Périme dans ${days} jours`;
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.background },
  toolbar: { paddingHorizontal: spacing.lg, paddingTop: spacing.md },
  search: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 11,
    fontSize: 15,
    color: colors.ink,
  },
  filters: { marginTop: spacing.md },
  toolbarButtons: { flexDirection: 'row', gap: spacing.md, marginTop: spacing.md },
  toolbarButton: { flex: 1 },
  nudge: {
    marginHorizontal: spacing.lg,
    marginTop: spacing.md,
    backgroundColor: colors.primarySoft,
    borderRadius: radius.lg,
    padding: spacing.md,
  },
  nudgeText: { fontSize: 14, fontWeight: '600', color: colors.ink },
  nudgeActions: { flexDirection: 'row', gap: spacing.sm, marginTop: spacing.sm },
  nudgeButton: { flex: 1 },
  list: { padding: spacing.lg, paddingBottom: 96 },
  row: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    padding: spacing.lg,
    marginBottom: spacing.md,
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: spacing.md,
  },
  rowPressed: { opacity: 0.7 },
  rowMain: { flex: 1 },
  rowTitle: { fontSize: 16, fontWeight: '700', color: colors.ink },
  rowBrand: { fontSize: 13, color: colors.inkMuted, marginTop: 1 },
  rowMeta: { fontSize: 14, color: colors.ink, marginTop: spacing.sm },
  rowExpiry: { fontSize: 13, color: colors.inkMuted, marginTop: 2 },
  fab: {
    position: 'absolute',
    right: spacing.xl,
    bottom: spacing.xl,
    width: 60,
    height: 60,
    borderRadius: 30,
    backgroundColor: colors.primary,
    alignItems: 'center',
    justifyContent: 'center',
    elevation: 4,
    shadowColor: '#000',
    shadowOpacity: 0.2,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 3 },
  },
  fabLabel: { color: '#FFFFFF', fontSize: 32, lineHeight: 36, fontWeight: '300' },
});
