import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { api } from '../src/api/client';
import type { CurrentStockSummary, Product } from '../src/api/types';
import { Button, Card } from '../src/components/ui';
import { colors, radius, spacing } from '../src/theme';

/** Codes-barres réellement utilisés sur les emballages alimentaires. */
const BARCODE_TYPES = ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39'] as const;

type Result =
  | { found: true; product: Product; currentStock: CurrentStockSummary | null }
  | { found: false; barcode: string };

/**
 * Le mode « produits douteux » de l'Évolution 3 (`tasks/evolutions.md`) : un
 * scan isolé et purement informatif, distinct de l'onglet Scanner — celui-ci
 * n'ajoute jamais rien au stock ni à un panier, il dit seulement ce qu'il y a
 * déjà chez soi avant de décider d'acheter.
 */
export default function ShoppingCheckScreen() {
  const router = useRouter();
  const [permission, requestPermission] = useCameraPermissions();
  const [active, setActive] = useState(true);
  const [result, setResult] = useState<Result | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const lookedUp = useRef(false);

  useFocusEffect(
    useCallback(() => {
      setActive(true);
      return () => setActive(false);
    }, []),
  );

  async function onScanned({ data }: { data: string }) {
    if (lookedUp.current) return;
    lookedUp.current = true;
    setActive(false);
    setLoading(true);
    setError(null);

    try {
      const response = await api.lookupBarcode(data);
      setResult(
        response.found
          ? { found: true, product: response.product, currentStock: response.currentStock }
          : { found: false, barcode: data },
      );
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'Vérification impossible.');
      lookedUp.current = false;
      setActive(true);
    } finally {
      setLoading(false);
    }
  }

  function scanNext() {
    setResult(null);
    setError(null);
    lookedUp.current = false;
    setActive(true);
  }

  if (!permission) {
    return <View style={styles.centered} />;
  }

  if (!permission.granted) {
    return (
      <View style={styles.centered}>
        <Text style={styles.title}>Accès à la caméra</Text>
        <Text style={styles.description}>
          La caméra sert uniquement à lire le code-barres du produit à vérifier.
        </Text>
        <Button label="Autoriser la caméra" onPress={requestPermission} style={styles.action} />
      </View>
    );
  }

  return (
    <View style={styles.flex}>
      {active ? (
        <CameraView
          style={StyleSheet.absoluteFill}
          facing="back"
          barcodeScannerSettings={{ barcodeTypes: [...BARCODE_TYPES] }}
          onBarcodeScanned={(event) => void onScanned(event)}
        />
      ) : (
        <View style={[StyleSheet.absoluteFill, styles.paused]} />
      )}

      <View style={styles.overlay} pointerEvents="box-none">
        {active ? (
          <>
            <View style={styles.reticle} />
            <Text style={styles.hint}>Cadrez le code-barres du produit à vérifier</Text>
          </>
        ) : null}

        {loading ? (
          <Card style={styles.card}>
            <Text style={styles.loadingText}>Vérification…</Text>
          </Card>
        ) : null}

        {error ? (
          <Card style={styles.card}>
            <Text style={styles.errorText}>{error}</Text>
            <Button label="Réessayer" onPress={scanNext} style={styles.spaced} />
          </Card>
        ) : null}

        {result ? <ResultCard result={result} onScanNext={scanNext} /> : null}
      </View>

      <View style={styles.footer}>
        <Button label="Fermer" variant="secondary" onPress={() => router.back()} />
      </View>
    </View>
  );
}

function ResultCard({ result, onScanNext }: { result: Result; onScanNext: () => void }) {
  if (!result.found) {
    return (
      <Card style={styles.card}>
        <Text style={styles.productName}>Produit inconnu</Text>
        <Text style={styles.stockText}>
          Ce code-barres ({result.barcode}) n'est pas dans le catalogue — impossible de savoir ce que vous en
          avez déjà.
        </Text>
        <Button label="Scanner un autre produit" onPress={onScanNext} style={styles.spaced} />
      </Card>
    );
  }

  const { product, currentStock } = result;

  return (
    <Card style={styles.card}>
      <Text style={styles.productName}>{product.displayName}</Text>
      {currentStock ? (
        <Text style={styles.stockText}>
          Vous avez déjà {currentStock.quantity} {currentStock.unitLabel}
          {currentStock.nextUseBy ? `, à consommer avant le ${formatDate(currentStock.nextUseBy)}` : ''}.
        </Text>
      ) : (
        <Text style={styles.stockText}>Rien en stock actuellement pour ce produit.</Text>
      )}
      <Button label="Scanner un autre produit" onPress={onScanNext} style={styles.spaced} />
    </Card>
  );
}

function formatDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: '#000000' },
  paused: { backgroundColor: '#000000' },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
    backgroundColor: colors.background,
  },
  title: { fontSize: 20, fontWeight: '700', color: colors.ink, marginBottom: spacing.sm },
  description: { fontSize: 15, color: colors.inkMuted, textAlign: 'center', lineHeight: 21 },
  action: { alignSelf: 'stretch', marginTop: spacing.xl },
  overlay: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  reticle: {
    width: '80%',
    height: 170,
    borderWidth: 3,
    borderColor: '#FFFFFF',
    borderRadius: radius.lg,
    opacity: 0.9,
  },
  hint: {
    color: '#FFFFFF',
    fontSize: 15,
    fontWeight: '600',
    marginTop: spacing.lg,
    textShadowColor: 'rgba(0,0,0,0.6)',
    textShadowRadius: 4,
  },
  card: { width: '100%' },
  loadingText: { fontSize: 15, color: colors.ink, textAlign: 'center' },
  errorText: { fontSize: 15, color: colors.danger },
  productName: { fontSize: 19, fontWeight: '800', color: colors.ink },
  stockText: { fontSize: 15, color: colors.inkMuted, marginTop: spacing.md, lineHeight: 21 },
  spaced: { marginTop: spacing.lg },
  footer: { position: 'absolute', bottom: spacing.xl, left: spacing.xl, right: spacing.xl },
});
