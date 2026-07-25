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
import { Badge, Chip, EmptyState, ErrorBanner, Loader } from '../../src/components/ui';
import { syncExpiryNotifications } from '../../src/notifications/expiry';
import { colors, radius, spacing, statusPresentation } from '../../src/theme';

const EXPIRY_FILTER_DAYS = 3;

export default function StockScreen() {
  const router = useRouter();

  const [items, setItems] = useState<StockItem[]>([]);
  const [reference, setReference] = useState<Reference | null>(null);
  const [search, setSearch] = useState('');
  const [location, setLocation] = useState<string | null>(null);
  const [category, setCategory] = useState<string | null>(null);
  const [urgentOnly, setUrgentOnly] = useState(false);
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
  }, [search, location, category, urgentOnly]);

  // Le stock change depuis d'autres écrans (scan, consommation, recette) :
  // on recharge à chaque retour sur l'onglet.
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  // Les alertes se programment sur l'inventaire ENTIER, pas sur la liste filtrée :
  // filtrer sur « Bientôt périmés » ne doit pas annuler les alertes des autres lots.
  useFocusEffect(
    useCallback(() => {
      let cancelled = false;

      (async () => {
        try {
          const all = await api.stockItems();
          if (!cancelled) await syncExpiryNotifications(all.items);
        } catch {
          // Une alerte non programmée ne doit jamais empêcher de consulter son stock.
        }
      })();

      return () => {
        cancelled = true;
      };
    }, []),
  );

  if (loading) return <Loader label="Chargement du stock…" />;

  const urgentCount = items.filter(
    (item) => item.status === 'expiring_soon' || item.status === 'expired',
  ).length;

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
      </View>

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
          <EmptyState
            title="Votre stock est vide"
            description="Scannez un code-barres ou saisissez un aliment manuellement pour commencer."
          />
        }
        renderItem={({ item }) => (
          <StockRow item={item} onPress={() => router.push(`/item/${item.id}`)} />
        )}
      />

      <Pressable style={styles.fab} onPress={() => router.push('/add')}>
        <Text style={styles.fabLabel}>+</Text>
      </Pressable>
    </View>
  );
}

function StockRow({ item, onPress }: { item: StockItem; onPress: () => void }) {
  const presentation = statusPresentation[item.status] ?? statusPresentation.sealed;

  return (
    <Pressable onPress={onPress} style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}>
      <View style={styles.rowMain}>
        <Text style={styles.rowTitle} numberOfLines={1}>
          {item.product.name}
        </Text>
        {item.product.brand ? (
          <Text style={styles.rowBrand} numberOfLines={1}>
            {item.product.brand}
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

function formatExpiry(item: StockItem): string {
  if (item.expirationDate === null) return 'Aucune date limite renseignée';

  const days = item.daysUntilExpiration;
  if (days === null) return 'Aucune date limite renseignée';
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
