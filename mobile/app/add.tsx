import DateTimePicker from '@react-native-community/datetimepicker';
import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api, generateOperationKey } from '../src/api/client';
import type { Product, ProductTraitValue, Reference, ShelfLifeEstimate, Unit } from '../src/api/types';
import { Button, Card, Chip, ErrorBanner, Field, Loader, SectionTitle } from '../src/components/ui';
import { colors, radius, spacing } from '../src/theme';

/**
 * Un lot à créer : une date de péremption et le nombre d'exemplaires qui la portent.
 *
 * Trois yaourts pour le 27 et deux pour le 3 août, c'est un seul passage en caisse
 * mais deux lots. Les fondre en un seul ferait mentir la date la plus proche —
 * donc les alertes, et la consommation FEFO.
 */
type Batch = {
  id: number;
  packages: string;
  expiration: Date | null;
  /** Aucune date imprimée : le serveur la déduit d'une durée de conservation. */
  estimate: boolean;
};

let nextBatchId = 1;

function newBatch(packages = '1'): Batch {
  return { id: nextBatchId++, packages, expiration: null, estimate: false };
}

export default function AddScreen() {
  const router = useRouter();
  const { barcode } = useLocalSearchParams<{ barcode?: string }>();

  const [reference, setReference] = useState<Reference | null>(null);
  const [product, setProduct] = useState<Product | null>(null);
  const [lookupMessage, setLookupMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const [name, setName] = useState('');
  const [brand, setBrand] = useState('');
  const [quantity, setQuantity] = useState('');
  const [unit, setUnit] = useState<Unit>('g');
  const [location, setLocation] = useState('pantry');
  const [traits, setTraits] = useState<ProductTraitValue[]>([]);
  const [batches, setBatches] = useState<Batch[]>([newBatch()]);
  const [pickerFor, setPickerFor] = useState<number | null>(null);
  const [freshness, setFreshness] = useState<ShelfLifeEstimate | null>(null);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  /**
   * Conservée jusqu'au succès : un double appui, ou une réponse réseau perdue
   * suivie d'une nouvelle tentative, ne doit pas créer les mêmes lots deux fois.
   */
  const operationKey = useRef<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const ref = await api.reference();
        if (!cancelled) setReference(ref);

        if (barcode) {
          try {
            const found = await api.lookupBarcode(barcode);
            if (cancelled) return;

            setProduct(found.product);
            setName(found.product.name);
            setBrand(found.product.brand ?? '');
            // L'état déjà déduit du libellé par le serveur reste modifiable ici.
            setTraits(found.product.traits);
            if (found.product.packageQuantity) setQuantity(String(found.product.packageQuantity));
            if (found.product.packageUnit) setUnit(found.product.packageUnit);
          } catch (exception) {
            // La base est collaborative : un produit absent bascule sur la saisie manuelle.
            if (!cancelled) {
              setLookupMessage(
                exception instanceof ApiError && exception.status === 404
                  ? `Code-barres ${barcode} inconnu d'Open Food Facts. Complétez les informations ci-dessous.`
                  : 'Recherche du produit impossible. Complétez les informations ci-dessous.',
              );
            }
          }
        }
      } catch (exception) {
        if (!cancelled) {
          setError(exception instanceof Error ? exception.message : 'Chargement impossible.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [barcode]);

  const total = useMemo(() => {
    const per = Number(quantity.replace(',', '.')) || 0;
    const count = batches.reduce(
      (sum, batch) => sum + Math.max(1, Math.round(Number(batch.packages.replace(',', '.')) || 1)),
      0,
    );
    return count * per;
  }, [batches, quantity]);

  const effectiveName = (product?.name ?? name).trim();

  /**
   * Demande au serveur combien de temps cet aliment se garde là où il est rangé.
   *
   * L'estimation est affichée avec sa justification : une date déduite qu'on ne
   * peut pas discuter finirait par n'être crue par personne.
   */
  async function estimateFreshness(batchId: number) {
    if (!effectiveName) {
      setError('Indiquez d\'abord le nom de l\'aliment pour estimer sa fraîcheur.');
      return;
    }

    try {
      const estimate = await api.shelfLife({
        name: effectiveName,
        category: product?.category,
        storageLocation: location,
        traits,
      });
      setFreshness(estimate);
      setBatches((current) =>
        current.map((batch) =>
          batch.id === batchId
            ? { ...batch, estimate: true, expiration: fromIsoDate(estimate.until) }
            : batch,
        ),
      );
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Estimation impossible.');
    }
  }

  function updateBatch(id: number, changes: Partial<Batch>) {
    setBatches((current) =>
      current.map((batch) => (batch.id === id ? { ...batch, ...changes } : batch)),
    );
  }

  async function submit() {
    setError(null);

    const perPackage = Number(quantity.replace(',', '.'));
    if (!Number.isFinite(perPackage) || perPackage <= 0) {
      setError('Indiquez une quantité par exemplaire strictement positive.');
      return;
    }
    if (!product && !name.trim()) {
      setError('Indiquez au minimum un nom d\'aliment.');
      return;
    }

    setBusy(true);
    if (!operationKey.current) operationKey.current = generateOperationKey();

    try {
      await api.createStockItem(
        {
          productId: product?.id,
          barcode: product ? undefined : barcode,
          name: product ? undefined : name.trim(),
          brand: product ? undefined : brand.trim() || undefined,
          numberOfPackages: 1,
          quantityPerPackage: perPackage,
          unit,
          storageLocation: location,
          // Sans sélection, le serveur déduit l'état du libellé plutôt que de le vider.
          traits: traits.length > 0 ? traits : undefined,
          note: note.trim() || undefined,
          // Une ligne par date : le serveur crée les lots en une seule transaction.
          batches: batches.map((batch) => ({
            numberOfPackages: Math.max(1, Math.round(Number(batch.packages.replace(',', '.')) || 1)),
            expirationDate: batch.estimate || !batch.expiration ? null : toIsoDate(batch.expiration),
            estimateExpiration: batch.estimate,
          })),
        },
        operationKey.current,
      );
      operationKey.current = null;
      router.replace('/(tabs)');
    } catch (exception) {
      // Une panne de transport n'a rien pu écrire : la même clé sert au prochain essai.
      if (!(exception instanceof ApiError) || exception.status !== 0) {
        operationKey.current = null;
      }
      setError(exception instanceof Error ? exception.message : 'Ajout impossible.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loader label="Recherche du produit…" />;

  const compatibleUnits = reference?.units ?? [];

  return (
    <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
      <ErrorBanner message={error} />

      {product ? (
        <Card style={styles.productCard}>
          <View style={styles.productRow}>
            {product.imageUrl ? (
              <Image source={{ uri: product.imageUrl }} style={styles.thumb} />
            ) : (
              <View style={[styles.thumb, styles.thumbEmpty]}>
                <Text style={styles.thumbGlyph}>🥫</Text>
              </View>
            )}
            <View style={styles.flex}>
              <Text style={styles.productName}>{product.name}</Text>
              {product.brand ? <Text style={styles.productBrand}>{product.brand}</Text> : null}
              <Text style={styles.productMeta}>
                {product.packageQuantity
                  ? `Conditionnement : ${product.packageQuantity} ${product.packageUnit ?? ''}`
                  : 'Conditionnement non renseigné'}
              </Text>
              {product.category ? (
                <Text style={styles.productMeta}>{product.category}</Text>
              ) : null}
            </View>
          </View>
        </Card>
      ) : (
        <>
          {lookupMessage ? (
            <View style={styles.notice}>
              <Text style={styles.noticeText}>{lookupMessage}</Text>
            </View>
          ) : null}
          <Field
            label="Nom de l'aliment"
            value={name}
            onChangeText={setName}
            placeholder="Tomates, riz, reste de gratin…"
          />
          <Field label="Marque (facultatif)" value={brand} onChangeText={setBrand} />
        </>
      )}

      <SectionTitle>Ce que vous ajoutez réellement</SectionTitle>

      {/* « 500 » en gris se lisait comme une valeur déjà saisie : on appuyait sur
          « Ajouter au stock » et le formulaire refusait un champ qui semblait
          rempli. Un exemple explicite ne peut plus être confondu avec une saisie. */}
      <Field
        label="Quantité par exemplaire"
        value={quantity}
        onChangeText={setQuantity}
        keyboardType="decimal-pad"
        placeholder="ex. 500"
        hint="Le contenu d'UN paquet, pas le total : 3 sachets de 500 g, c'est 500."
      />

      <Text style={styles.fieldLabel}>Unité</Text>
      <View style={styles.chips}>
        {compatibleUnits.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={unit === option.value}
            onPress={() => setUnit(option.value as Unit)}
          />
        ))}
      </View>

      {total > 0 ? (
        <Text style={styles.total}>
          Total ajouté au stock : {formatNumber(total)} {unit}
        </Text>
      ) : null}

      <SectionTitle>Dans quel état est-il ?</SectionTitle>
      <Text style={styles.help}>
        Ce que les recettes doivent savoir : des pommes de terre en conserve ne s'épluchent
        pas, un légume surgelé se décongèle.
      </Text>
      <View style={styles.chips}>
        {reference?.productTraits.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={traits.includes(option.value)}
            onPress={() =>
              setTraits((current) =>
                current.includes(option.value)
                  ? current.filter((trait) => trait !== option.value)
                  : [...current, option.value],
              )
            }
          />
        ))}
      </View>

      <SectionTitle>Où est-il rangé ?</SectionTitle>
      <View style={styles.chips}>
        {reference?.storageLocations.map((option) => (
          <Chip
            key={option.value}
            label={option.label}
            selected={location === option.value}
            onPress={() => setLocation(option.value)}
          />
        ))}
      </View>

      <SectionTitle>Dates de péremption</SectionTitle>
      <Text style={styles.help}>
        Une ligne par date. Trois yaourts pour le 27 et deux pour le 3 août font deux
        lots : c'est ce qui permet de consommer le plus urgent en premier.
      </Text>

      {batches.map((batch, position) => (
        <Card key={batch.id} style={styles.batchCard}>
          <View style={styles.batchHeader}>
            <Text style={styles.batchTitle}>Lot {position + 1}</Text>
            {batches.length > 1 ? (
              <Pressable
                onPress={() => setBatches((current) => current.filter((entry) => entry.id !== batch.id))}
                accessibilityRole="button"
                accessibilityLabel={`Retirer le lot ${position + 1}`}
              >
                <Text style={styles.clearDateText}>Retirer</Text>
              </Pressable>
            ) : null}
          </View>

          <View style={styles.pair}>
            <Field
              label="Exemplaires"
              value={batch.packages}
              onChangeText={(value) => updateBatch(batch.id, { packages: value })}
              keyboardType="number-pad"
              containerStyle={styles.pairInput}
            />
            <View style={styles.pairInput}>
              <Text style={styles.fieldLabel}>À consommer avant</Text>
              <Pressable
                style={styles.dateButton}
                onPress={() => setPickerFor(batch.id)}
                accessibilityRole="button"
                accessibilityLabel={
                  batch.expiration
                    ? `Lot ${position + 1}, à consommer avant le ${formatDate(batch.expiration)}`
                    : `Lot ${position + 1}, aucune date de péremption`
                }
                accessibilityHint="Ouvre le sélecteur de date"
              >
                <Text style={styles.dateText}>
                  {batch.expiration ? formatDate(batch.expiration) : 'Aucune date'}
                </Text>
              </Pressable>
            </View>
          </View>

          <View style={styles.chips}>
            {[
              { label: 'Dans 3 jours', days: 3 },
              { label: 'Dans 1 semaine', days: 7 },
              { label: 'Dans 1 mois', days: 30 },
            ].map((shortcut) => (
              <Chip
                key={shortcut.days}
                label={shortcut.label}
                selected={false}
                onPress={() =>
                  updateBatch(batch.id, { expiration: addDays(shortcut.days), estimate: false })
                }
              />
            ))}
            {/* Fruits, légumes, vrac, restes : rien n'est imprimé sur l'emballage. */}
            <Chip
              label="Pas de date : estimer"
              selected={batch.estimate}
              onPress={() => estimateFreshness(batch.id)}
            />
            {batch.expiration ? (
              <Chip
                label="Effacer"
                selected={false}
                onPress={() => updateBatch(batch.id, { expiration: null, estimate: false })}
              />
            ) : null}
          </View>

          {batch.estimate && freshness ? (
            <Text style={styles.estimateHint}>
              Estimation, pas une date lue : {freshness.explanation} Elle sera signalée
              comme telle dans votre stock.
            </Text>
          ) : null}
        </Card>
      ))}

      <Button
        label="Ajouter une autre date"
        variant="secondary"
        onPress={() => setBatches((current) => [...current, newBatch()])}
      />

      {pickerFor !== null ? (
        <DateTimePicker
          value={batches.find((batch) => batch.id === pickerFor)?.expiration ?? addDays(7)}
          mode="date"
          onChange={(event, selected) => {
            const target = pickerFor;
            setPickerFor(null);
            if (event.type === 'set' && selected && target !== null) {
              updateBatch(target, { expiration: selected, estimate: false });
            }
          }}
        />
      ) : null}

      <Field
        label="Note (facultatif)"
        value={note}
        onChangeText={setNote}
        placeholder="Reste du repas de dimanche…"
      />

      <Button label="Ajouter au stock" onPress={submit} loading={busy} />
    </ScrollView>
  );
}

function addDays(days: number): Date {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date;
}

function toIsoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

/** « 2026-08-02 » interprété localement : `new Date(iso)` serait en UTC, donc décalé d'un jour. */
function fromIsoDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

function formatDate(date: Date): string {
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatNumber(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, paddingBottom: spacing.xxl * 2 },
  flex: { flex: 1 },
  productCard: { marginBottom: spacing.lg },
  productRow: { flexDirection: 'row', gap: spacing.md },
  thumb: { width: 64, height: 64, borderRadius: radius.md, backgroundColor: colors.surfaceMuted },
  thumbEmpty: { alignItems: 'center', justifyContent: 'center' },
  thumbGlyph: { fontSize: 28 },
  productName: { fontSize: 17, fontWeight: '700', color: colors.ink },
  productBrand: { fontSize: 14, color: colors.inkMuted, marginTop: 1 },
  productMeta: { fontSize: 13, color: colors.inkMuted, marginTop: spacing.xs },
  notice: {
    backgroundColor: colors.accentSoft,
    borderRadius: radius.md,
    padding: spacing.md,
    marginBottom: spacing.lg,
  },
  noticeText: { color: colors.accent, fontSize: 14, lineHeight: 20 },
  pair: { flexDirection: 'row', gap: spacing.md },
  pairInput: { flex: 1 },
  fieldLabel: {
    fontSize: 13,
    fontWeight: '600',
    color: colors.inkMuted,
    marginBottom: spacing.sm,
  },
  chips: { flexDirection: 'row', flexWrap: 'wrap' },
  help: { fontSize: 13, color: colors.inkMuted, lineHeight: 19, marginBottom: spacing.md },
  total: {
    fontSize: 15,
    fontWeight: '600',
    color: colors.primary,
    marginTop: spacing.xs,
    marginBottom: spacing.sm,
  },
  batchCard: { marginBottom: spacing.md },
  batchHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.sm,
  },
  batchTitle: { fontSize: 15, fontWeight: '700', color: colors.ink },
  estimateHint: {
    fontSize: 13,
    color: colors.accent,
    lineHeight: 19,
    marginTop: spacing.sm,
  },
  dateButton: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 13,
  },
  dateText: { fontSize: 15, color: colors.ink },
  clearDateText: { color: colors.danger, fontSize: 14, fontWeight: '600' },
});
