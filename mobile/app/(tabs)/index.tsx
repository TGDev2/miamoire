import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useMemo, useState } from 'react';
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

import { api, generateOperationKey } from '../../src/api/client';
import type { Reference, StockItem } from '../../src/api/types';
import { useFeedback } from '../../src/components/Feedback';
import { Horizon, matchesHorizon, type HorizonSelection } from '../../src/components/Horizon';
import {
  Button,
  Chip,
  EmptyState,
  ErrorBanner,
  Icon,
  ScreenHeader,
  Skeleton,
  Stamp,
  useTopInset,
} from '../../src/components/ui';
import { deadlineOf } from '../../src/deadline';
import * as haptics from '../../src/haptics';
import { resyncExpiryAlerts } from '../../src/notifications/useExpiryAlerts';
import {
  colors,
  deadlineSentence,
  font,
  radius,
  spacing,
  statusPresentation,
  text,
  thermal,
  thermalFor,
} from '../../src/theme';

/** Les états qui décrivent l'emballage, et que le cachet ne dit pas. */
const PACKAGE_STATES = new Set(['opened', 'resealed', 'running_low', 'empty', 'archived']);

/**
 * « Crème » et « creme » doivent trouver la même chose.
 *
 * Décomposer puis retirer les diacritiques est la seule normalisation dont la
 * recherche ait besoin : elle porte sur des noms d'aliments, pas sur du texte
 * libre.
 *
 * La plage combinante est construite par `RegExp` à partir d'échappements, et
 * non écrite littéralement : posés seuls dans un fichier source, ces caractères
 * n'ont pas de lettre à décorer, et le premier outil qui repasse le fichier en
 * NFC les recolle silencieusement sur le crochet voisin. Pas d'échappement de
 * propriété Unicode non plus — le moteur embarqué ne le garantit pas.
 */
const DIACRITICS = new RegExp('[\\u0300-\\u036f]', 'g');

function fold(value: string): string {
  return value.normalize('NFD').replace(DIACRITICS, '').toLowerCase();
}

export default function StockScreen() {
  const router = useRouter();
  const topInset = useTopInset();
  const { commitWithUndo } = useFeedback();

  /**
   * L'inventaire entier, tel que le serveur le rend. Recherche, emplacement,
   * catégorie et jour se calculent ensuite en mémoire.
   *
   * Ils passaient tous par l'URL, et `load` dépendait donc de `search` : taper
   * « tomate » lançait six requêtes, chacune rechargeant au passage le
   * référentiel — et rien n'empêchait la réponse d'un préfixe court d'arriver
   * après celle du mot entier, ni donc d'afficher des résultats qui ne
   * correspondaient plus au champ.
   *
   * Ce même inventaire non filtré alimente l'horizon et le compteur d'urgents,
   * qui doivent compter le stock et non ce que l'écran montre (leçon nº 6) :
   * l'ancien compteur baissait dès qu'on filtrait sur « Frigo ».
   */
  const [items, setItems] = useState<StockItem[] | null>(null);
  const [reference, setReference] = useState<Reference | null>(null);

  const [search, setSearch] = useState('');
  const [location, setLocation] = useState<string | null>(null);
  const [category, setCategory] = useState<string | null>(null);
  const [horizon, setHorizon] = useState<HorizonSelection>(null);
  /** Les archives sont un autre inventaire, pas un filtre qui s'ajoute aux autres. */
  const [archived, setArchived] = useState(false);

  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** Les lots dont le retrait est engagé mais pas encore parti : voir `commitWithUndo`. */
  const [pending, setPending] = useState<string[]>([]);

  const load = useCallback(async () => {
    setError(null);
    try {
      const [stock, ref] = await Promise.all([
        api.stockItems({ archived: archived || undefined }),
        api.reference(),
      ]);
      setItems(stock.items);
      setReference(ref);
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
      // Sans cela, un échec réseau laissait `items` à vide, donc l'écran
      // annonçait « Votre stock est vide » — la seule lecture qui décourage.
      setItems(null);
    } finally {
      setRefreshing(false);
    }
  }, [archived]);

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

  const inventory = items ?? [];

  const visible = useMemo(() => {
    const needle = fold(search.trim());

    return inventory.filter((item) => {
      if (!matchesHorizon(item, horizon)) return false;
      if (null !== location && item.storageLocation !== location) return false;
      if (null !== category && item.variant.category !== category) return false;
      if (!needle) return true;

      return (
        fold(item.variant.culinaryLabel).includes(needle) ||
        fold(item.variant.name).includes(needle) ||
        (null !== item.variant.brand && fold(item.variant.brand).includes(needle))
      );
    });
  }, [inventory, search, location, category, horizon]);

  const urgentCount = inventory.filter(
    (item) => 'expiring_soon' === item.status || 'expired' === item.status,
  ).length;

  /** Une liste vide ne veut pas dire la même chose selon qu'on filtre ou non. */
  const filtering = Boolean(search) || null !== location || null !== category || null !== horizon;

  /**
   * Retirer un exemplaire sans quitter la liste.
   *
   * Le geste le plus fréquent de l'application demandait trois taps et un
   * changement d'écran : Stock, « J'ai utilisé… », puis la carte. Il en demande
   * un. La ligne s'estompe aussitôt, rien n'est écrit avant cinq secondes, et
   * la liste ne se recharge qu'une fois le retrait réellement parti.
   */
  function consumeOne(item: StockItem) {
    haptics.removed();
    setPending((current) => [...current, item.id]);

    const operationKey = generateOperationKey();

    commitWithUndo({
      message: `${item.variant.culinaryLabel} — 1 ${unitTaken(item)} en moins`,
      tone: thermalFor(deadlineOf(item).days),
      commit: async () => {
        await api.consume(item.id, { mode: 'package' }, operationKey);
        setPending((current) => current.filter((id) => id !== item.id));
        await load();
      },
      onUndo: () => setPending((current) => current.filter((id) => id !== item.id)),
      onFailed: (message) => {
        setPending((current) => current.filter((id) => id !== item.id));
        setError(message);
      },
    });
  }

  return (
    <View style={[styles.flex, topInset]}>
      <FlatList
        data={visible}
        keyExtractor={(item) => item.id}
        contentContainerStyle={styles.list}
        // Le clavier couvrait la moitié des résultats et ne se repliait pas au
        // défilement ; le premier tap sur une ligne ne servait qu'à le fermer.
        keyboardDismissMode="on-drag"
        keyboardShouldPersistTaps="handled"
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
              eyebrow={inventoryLabel({
                shown: visible.length,
                total: inventory.length,
                urgent: urgentCount,
                archived,
                filtering,
                loading: null === items,
              })}
              title={archived ? 'Archives' : 'Mon stock'}
            />

            <ErrorBanner message={error} />

            {/* Les archives sont un inventaire clos : elles n'ont pas d'avenir
                à dessiner. Le reste du temps, la bande remplace à la fois le
                filtre « À consommer vite » et les deux raccourcis qui vivaient
                ici — elle en dit plus, et prend moins de place que les trois. */}
            {archived || null === items ? null : (
              <Horizon
                items={inventory}
                selection={horizon}
                onSelect={(value) => {
                  haptics.select();
                  setHorizon(value);
                }}
              />
            )}

            <View style={styles.search}>
              <Icon name="magnify" size={18} />
              <TextInput
                value={search}
                onChangeText={setSearch}
                placeholder="Rechercher un aliment"
                placeholderTextColor={colors.inkMuted}
                style={styles.searchInput}
                returnKeyType="search"
                autoCorrect={false}
                accessibilityLabel="Rechercher un aliment"
              />
              {search ? (
                <Pressable
                  onPress={() => setSearch('')}
                  hitSlop={14}
                  accessibilityRole="button"
                  accessibilityLabel="Effacer la recherche"
                >
                  <Icon name="close" size={18} />
                </Pressable>
              ) : null}
            </View>

            <ScrollView
              horizontal
              showsHorizontalScrollIndicator={false}
              keyboardShouldPersistTaps="handled"
              style={styles.filters}
            >
              {reference?.storageLocations.map((option) => (
                <Chip
                  key={option.value}
                  label={option.label}
                  selected={location === option.value}
                  onPress={() => {
                    haptics.select();
                    setLocation(location === option.value ? null : option.value);
                  }}
                />
              ))}
              {reference?.categories.map((name) => (
                <Chip
                  key={name}
                  label={name}
                  selected={category === name}
                  onPress={() => {
                    haptics.select();
                    setCategory(category === name ? null : name);
                  }}
                />
              ))}
              {/* Les archives n'étaient atteignables par aucun écran : le geste
                  s'annonçait réversible sans que rien ne permette de le défaire.
                  Placées en dernier, elles ne s'ajoutent pas aux filtres — elles
                  ouvrent un autre inventaire. */}
              <Chip
                label="Archives"
                selected={archived}
                onPress={() => {
                  haptics.select();
                  setHorizon(null);
                  setArchived((value) => !value);
                }}
              />
            </ScrollView>

            {null === items ? <Skeleton /> : null}
          </View>
        }
        ListEmptyComponent={
          // Un chargement, un échec, une recherche sans résultat et un stock
          // réellement vide sont quatre choses différentes. Elles s'affichaient
          // toutes comme la dernière.
          null === items ? null : archived && !filtering ? (
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
          <StockRow
            item={item}
            pending={pending.includes(item.id)}
            onPress={() => router.push(`/item/${item.id}`)}
            onConsumeOne={() => consumeOne(item)}
          />
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

/**
 * La rubrique du titre porte le compte réel — pas un mot de remplissage.
 *
 * Filtrée, elle dit ce que l'écran montre ET sur combien : « 11 lots » posé
 * au-dessus d'une liste de trois se lit comme une liste incomplète. Le compteur
 * d'urgents, lui, ne s'affiche que hors filtre : il décrit le stock entier, et
 * le mêler à un sous-ensemble le rendrait à nouveau ambigu.
 */
function inventoryLabel({
  shown,
  total,
  urgent,
  archived,
  filtering,
  loading,
}: {
  shown: number;
  total: number;
  urgent: number;
  archived: boolean;
  filtering: boolean;
  loading: boolean;
}): string {
  if (loading) return 'Lecture du stock';

  const lots = (count: number) => `${count} lot${count > 1 ? 's' : ''}`;

  if (filtering) return `${lots(shown)} sur ${total}`;
  if (archived) return `${lots(total)} archivé${total > 1 ? 's' : ''}`;
  if (0 === total) return 'Aucun lot';

  return urgent > 0 ? `${lots(total)} · ${urgent} à traiter` : lots(total);
}

/**
 * Ce dont on retire une unité — le nom, jamais la phrase.
 *
 * « Un exemplaire » ne veut rien dire d'une courgette : le geste serveur est le
 * même (`mode: 'package'`), le mot ne l'est pas.
 *
 * Aucune tentative d'accorder un article sur le nom de l'aliment : il est
 * enregistré tel que la personne l'a écrit, souvent au pluriel, et « un
 * courgettes retiré » est exactement ce que produit une règle qui essaie. Les
 * appelants composent donc « 1 <nom> en moins », qui ne s'accorde avec rien.
 */
function unitTaken(item: StockItem): string {
  if ('fresh' !== item.variant.nature) return 'exemplaire';
  if (!item.isDiscreteUnit) return 'portion';

  return item.unitLabel;
}

/**
 * Le retrait direct n'est proposé que là où « un de moins » veut dire quelque
 * chose : un lot d'exemplaires, ou une unité qui se compte. Sur 600 g de
 * courgettes en vrac, il n'y a pas d'unité à retirer, et la fiche reste le bon
 * endroit — c'est la même retenue que la leçon nº 35.
 */
function offersDirectConsume(item: StockItem): boolean {
  return !item.isArchived && (item.isDiscreteUnit || item.numberOfPackages > 1);
}

const StockRow = React.memo(function StockRow({
  item,
  pending,
  onPress,
  onConsumeOne,
}: {
  item: StockItem;
  pending: boolean;
  onPress: () => void;
  onConsumeOne: () => void;
}) {
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
    <View style={[styles.row, pending && styles.rowPending]}>
      <View style={[styles.rowRail, { backgroundColor: thermal[level].line }]} />

      <Pressable
        onPress={onPress}
        style={({ pressed }) => [styles.rowMain, pressed && styles.rowPressed]}
        accessibilityRole="button"
        // Sans cela, le lecteur d'écran énumère cinq textes séparés puis un badge,
        // sans dire qu'ils forment une ligne ni qu'elle s'ouvre.
        accessibilityLabel={`${item.variant.culinaryLabel}, ${item.displayQuantity} ${item.displayUnit}, ${item.storageLocationLabel}, ${presentation.label}. ${deadlineSentence(deadline.days, deadline.estimated)}`}
        accessibilityHint="Ouvre la fiche de l'aliment"
      >
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
      </Pressable>

      {offersDirectConsume(item) ? (
        <Pressable
          onPress={onConsumeOne}
          disabled={pending}
          accessibilityRole="button"
          accessibilityLabel={`Retirer 1 ${unitTaken(item)} de ${item.variant.culinaryLabel}`}
          accessibilityState={{ disabled: pending }}
          style={({ pressed }) => [styles.take, pressed && !pending && styles.takePressed]}
        >
          <Text style={styles.takeLabel}>−1</Text>
        </Pressable>
      ) : null}
    </View>
  );
});

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
  filters: {
    marginTop: spacing.md,
    marginBottom: spacing.xs,
    marginHorizontal: -spacing.xl,
    paddingHorizontal: spacing.xl,
  },

  row: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    marginBottom: spacing.sm,
    flexDirection: 'row',
    alignItems: 'stretch',
    overflow: 'hidden',
  },
  /** Le retrait est engagé et part dans cinq secondes : la ligne le montre. */
  rowPending: { opacity: 0.45 },
  rowPressed: { backgroundColor: colors.surfaceSunk },
  rowRail: { width: 3 },
  rowMain: { flex: 1, padding: spacing.lg, paddingLeft: spacing.lg - 3 + spacing.xs },
  rowTop: { flexDirection: 'row', alignItems: 'flex-start', gap: spacing.md },
  rowTitle: { flex: 1 },
  rowMeta: { marginTop: spacing.sm },
  rowNote: { marginTop: 2 },

  take: {
    width: 52,
    alignItems: 'center',
    justifyContent: 'center',
    borderLeftWidth: 1,
    borderLeftColor: colors.border,
  },
  takePressed: { backgroundColor: colors.surfaceSunk },
  takeLabel: { fontFamily: font.monoSemiBold, fontSize: 14, lineHeight: 18, color: colors.ink },

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
