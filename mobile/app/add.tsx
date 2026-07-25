import DateTimePicker from '@react-native-community/datetimepicker';
import { useLocalSearchParams, useRouter } from 'expo-router';
import React, { useEffect, useMemo, useState } from 'react';
import { Image, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import { ApiError, api } from '../src/api/client';
import type { Product, Reference, Unit } from '../src/api/types';
import { Button, Card, Chip, ErrorBanner, Field, Loader, SectionTitle } from '../src/components/ui';
import { colors, radius, spacing } from '../src/theme';

export default function AddScreen() {
  const router = useRouter();
  const { barcode } = useLocalSearchParams<{ barcode?: string }>();

  const [reference, setReference] = useState<Reference | null>(null);
  const [product, setProduct] = useState<Product | null>(null);
  const [lookupMessage, setLookupMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const [name, setName] = useState('');
  const [brand, setBrand] = useState('');
  const [packages, setPackages] = useState('1');
  const [quantity, setQuantity] = useState('');
  const [unit, setUnit] = useState<Unit>('g');
  const [location, setLocation] = useState('pantry');
  const [expiration, setExpiration] = useState<Date | null>(null);
  const [showPicker, setShowPicker] = useState(false);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

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
    const count = Number(packages.replace(',', '.')) || 0;
    const per = Number(quantity.replace(',', '.')) || 0;
    return count * per;
  }, [packages, quantity]);

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
    try {
      await api.createStockItem({
        productId: product?.id,
        barcode: product ? undefined : barcode,
        name: product ? undefined : name.trim(),
        brand: product ? undefined : brand.trim() || undefined,
        numberOfPackages: Math.max(1, Math.round(Number(packages.replace(',', '.')) || 1)),
        quantityPerPackage: perPackage,
        unit,
        storageLocation: location,
        expirationDate: expiration ? toIsoDate(expiration) : null,
        note: note.trim() || undefined,
      });
      router.replace('/(tabs)');
    } catch (exception) {
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

      <View style={styles.pair}>
        <Field
          label="Exemplaires"
          value={packages}
          onChangeText={setPackages}
          keyboardType="number-pad"
          containerStyle={styles.pairInput}
        />
        <Field
          label="Quantité par exemplaire"
          value={quantity}
          onChangeText={setQuantity}
          keyboardType="decimal-pad"
          placeholder="500"
          containerStyle={styles.pairInput}
        />
      </View>

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

      <SectionTitle>Date de péremption</SectionTitle>
      <View style={styles.dateRow}>
        <Pressable style={styles.dateButton} onPress={() => setShowPicker(true)}>
          <Text style={styles.dateText}>
            {expiration ? formatDate(expiration) : 'Aucune date renseignée'}
          </Text>
        </Pressable>
        {expiration ? (
          <Pressable onPress={() => setExpiration(null)} style={styles.clearDate}>
            <Text style={styles.clearDateText}>Effacer</Text>
          </Pressable>
        ) : null}
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
            onPress={() => setExpiration(addDays(shortcut.days))}
          />
        ))}
      </View>

      {showPicker ? (
        <DateTimePicker
          value={expiration ?? addDays(7)}
          mode="date"
          onChange={(event, selected) => {
            setShowPicker(false);
            if (event.type === 'set' && selected) setExpiration(selected);
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
  total: {
    fontSize: 15,
    fontWeight: '600',
    color: colors.primary,
    marginTop: spacing.xs,
    marginBottom: spacing.sm,
  },
  dateRow: { flexDirection: 'row', alignItems: 'center', gap: spacing.md, marginBottom: spacing.md },
  dateButton: {
    flex: 1,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: spacing.md,
    paddingVertical: 13,
  },
  dateText: { fontSize: 15, color: colors.ink },
  clearDate: { paddingHorizontal: spacing.sm, paddingVertical: spacing.sm },
  clearDateText: { color: colors.danger, fontSize: 14, fontWeight: '600' },
});
