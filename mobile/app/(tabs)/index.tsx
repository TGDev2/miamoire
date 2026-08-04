import { useFocusEffect, useRouter } from 'expo-router';
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
import {
  Button,
  Chip,
  EmptyState,
  ErrorBanner,
  Icon,
  Loader,
  ScreenHeader,
  Stamp,
  useTopInset,
} from '../../src/components/ui';
import { deadlineOf } from '../../src/deadline';
import { resyncExpiryAlerts } from '../../src/notifications/useExpiryAlerts';
import {
  colors,
  deadlineSentence,
  radius,
  spacing,
  statusPresentation,
  text,
  thermal,
  thermalFor,
} from '../../src/theme';

const EXPIRY_FILTER_DAYS = 3;

/** Les états qui décrivent l'emballage, et que le cachet ne dit pas. */
const PACKAGE_STATES = new Set(['opened', 'resealed', 'running_low', 'empty', 'archived']);

export default function StockScreen() {
  const router = useRouter();
  const topInset = useTopInset();

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

  if (loading) return <Loader label="Chargement du stock" />;

  const urgentCount = items.filter(
    (item) => item.status === 'expiring_soon' || item.status === 'expired',
  ).length;

  /** Une liste vide ne veut pas dire la même chose selon qu'on filtre ou non. */
  const filtering = Boolean(search) || location !== null || category !== null || urgentOnly;

  return (
    <View style={[styles.flex, topInset]}>
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
            tintColor={colors.ink}
          />
        }
        ListHeaderComponent={
          <View>
            <ScreenHeader
              eyebrow={inventoryLabel(items.length, urgentCount, archived)}
              title={archived ? 'Archives' : 'Mon stock'}
            />

            <View style={styles.search}>
              <Icon name="magnify" size={18} />
              <TextInput
                value={search}
                onChangeText={setSearch}
                placeholder="Rechercher un aliment"
                placeholderTextColor={colors.inkMuted}
                style={styles.searchInput}
                returnKeyType="search"
                accessibilityLabel="Rechercher un aliment"
              />
              {search ? (
                <Pressable
                  onPress={() => setSearch('')}
                  hitSlop={8}
                  accessibilityRole="button"
                  accessibilityLabel="Effacer la recherche"
                >
                  <Icon name="close" size={18} />
                </Pressable>
              ) : null}
            </View>

            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.filters}>
              <Chip
                label={`À consommer vite${urgentCount ? ` (${urgentCount})` : ''}`}
                selected={urgentOnly}
                onPress={() => setUrgentOnly((value) => !value)}
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
              {/* Les archives n'étaient atteignables par aucun écran : le geste
                  s'annonçait réversible sans que rien ne permette de le défaire.
                  Placées en dernier, elles ne s'ajoutent pas aux filtres — elles
                  ouvrent un autre inventaire. */}
              <Chip label="Archives" selected={archived} onPress={() => setArchived((value) => !value)} />
            </ScrollView>

            <View style={styles.quickActions}>
              <Button
                label="J'ai utilisé…"
                variant="secondary"
                icon="silverware-fork-knife"
                onPress={() => router.push('/quick-consume')}
                hint="Retirer une quantité du stock"
                style={styles.quickAction}
              />
              <Button
                label="Vérifier"
                variant="secondary"
                icon="clipboard-check-outline"
                onPress={() => router.push('/review')}
                hint="Confirmer les lots dont l'état est incertain"
                style={styles.quickAction}
              />
            </View>

            <ErrorBanner message={error} />
          </View>
        }
        ListEmptyComponent={
          // « Votre stock est vide » sous une recherche active est faux, et c'est
          // la seule lecture qui décourage : on croit avoir perdu son inventaire
          // alors qu'on a simplement tapé un mot qui ne correspond à rien.
          // Les archives ajoutent un troisième cas, qui ne dit pas la même chose
          // que les deux autres : « rien n'a été rangé ici », pas « rien à voir ».
          archived && !filtering ? (
            <EmptyState
              icon="archive-outline"
              title="Aucun lot archivé"
              description="Archiver un aliment le sort de l'inventaire sans rien lui retirer. Il attend ici d'être restauré."
            />
          ) : filtering || archived ? (
            <EmptyState
              icon="magnify"
              title="Aucun résultat"
              description={
                search
                  ? `Rien ne correspond à « ${search} » avec les filtres actifs.`
                  : 'Aucun aliment ne correspond aux filtres actifs.'
              }
            />
          ) : (
            <EmptyState
              icon="basket-outline"
              title="Votre stock est vide"
              description="Scannez un code-barres, ou saisissez un premier aliment à la main."
              action={<Button label="Ajouter un aliment" onPress={() => router.push('/add')} />}
            />
          )
        }
        renderItem={({ item }) => (
          <StockRow item={item} onPress={() => router.push(`/item/${item.id}`)} />
        )}
      />

      <Pressable
        style={({ pressed }) => [styles.add, pressed && styles.addPressed]}
        onPress={() => router.push('/add')}
        accessibilityRole="button"
        accessibilityLabel="Ajouter un aliment au stock"
      >
        <Icon name="plus" size={26} color={colors.onInk} />
      </Pressable>
    </View>
  );
}

/** La rubrique du titre porte le compte réel — pas un mot de remplissage. */
function inventoryLabel(count: number, urgent: number, archived: boolean): string {
  if (archived) return `${count} lot${count > 1 ? 's' : ''} archivé${count > 1 ? 's' : ''}`;
  if (0 === count) return 'Aucun lot';

  const lots = `${count} lot${count > 1 ? 's' : ''}`;

  return urgent > 0 ? `${lots} · ${urgent} à traiter` : lots;
}

function StockRow({ item, onPress }: { item: StockItem; onPress: () => void }) {
  const presentation = statusPresentation[item.status] ?? statusPresentation.sealed;
  const deadline = deadlineOf(item);
  const level = thermalFor(deadline.days);

  // Le cachet dit déjà l'échéance : la pastille d'état ne se répète que pour ce
  // qu'il ne dit pas — l'emballage. Le reste passe en note discrète.
  const note = [
    PACKAGE_STATES.has(item.status) ? presentation.label.toLowerCase() : null,
    item.addedBy ? `ajouté par ${item.addedBy}` : null,
  ]
    .filter(Boolean)
    .join(' · ');

  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
      accessibilityRole="button"
      // Sans cela, le lecteur d'écran énumère cinq textes séparés puis un badge,
      // sans dire qu'ils forment une ligne ni qu'elle s'ouvre.
      accessibilityLabel={`${item.variant.culinaryLabel}, ${item.displayQuantity} ${item.displayUnit}, ${item.storageLocationLabel}, ${presentation.label}. ${deadlineSentence(deadline.days, deadline.estimated)}`}
      accessibilityHint="Ouvre la fiche de l'aliment"
    >
      <View style={[styles.rowRail, { backgroundColor: thermal[level].line }]} />

      <View style={styles.rowMain}>
        <View style={styles.rowTop}>
          {/* Le libellé culinaire distingue « Tomates » de « Tomates (en conserve) » :
              ce sont deux stocks, et la liste doit le montrer. */}
          <Text style={[text.heading, styles.rowTitle]} numberOfLines={2}>
            {item.variant.culinaryLabel}
          </Text>
          <Stamp days={deadline.days} date={deadline.date} estimated={deadline.estimated} decorative />
        </View>

        {/* Le tilde dit « la machine suppose » — le même signe que sur le cachet. */}
        <Text style={[text.data, styles.rowMeta]}>
          {item.quantityIsEstimated ? '~' : ''}
          {item.displayQuantity} {item.displayUnit} · {item.storageLocationLabel}
          {item.variant.brand ? ` · ${item.variant.brand}` : ''}
        </Text>

        {note ? <Text style={[text.small, styles.rowNote]}>{note}</Text> : null}
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.ground },
  list: { paddingHorizontal: spacing.xl, paddingBottom: 112 },

  search: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    minHeight: 48,
  },
  searchInput: { flex: 1, ...text.body, paddingVertical: 12 },
  filters: { marginTop: spacing.md, marginHorizontal: -spacing.xl, paddingHorizontal: spacing.xl },
  quickActions: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.lg },
  quickAction: { flex: 1 },

  row: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
    flexDirection: 'row',
    overflow: 'hidden',
  },
  rowPressed: { backgroundColor: colors.surfaceSunk },
  rowRail: { width: 3 },
  rowMain: { flex: 1, padding: spacing.lg, paddingLeft: spacing.lg - 3 + spacing.xs },
  rowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  rowTitle: { flex: 1 },
  rowMeta: { marginTop: spacing.sm },
  rowNote: { marginTop: 2 },

  add: {
    position: 'absolute',
    right: spacing.xl,
    bottom: spacing.xl,
    width: 56,
    height: 56,
    borderRadius: radius.lg,
    backgroundColor: colors.ink,
    alignItems: 'center',
    justifyContent: 'center',
  },
  addPressed: { backgroundColor: '#28353D' },
});
