import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { api } from '../src/api/client';
import type { CurrentStockSummary, Product } from '../src/api/types';
import { Button, Card } from '../src/components/ui';
import { RETAIL_BARCODE_TYPES, readRetailBarcode } from '../src/scan/retailBarcode';
import { colors, spacing, text, thermal } from '../src/theme';

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

  async function onScanned(scan: { type: string; data: string }) {
    // Écartée avant d'arrêter la caméra : une lecture fantôme ne doit pas
    // interrompre le cadrage du vrai code.
    const data = readRetailBarcode(scan);
    if (null === data || lookedUp.current) return;
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
          barcodeScannerSettings={{ barcodeTypes: [...RETAIL_BARCODE_TYPES] }}
          onBarcodeScanned={(event) => void onScanned(event)}
        />
      ) : (
        <View style={[StyleSheet.absoluteFill, styles.paused]} />
      )}

      <View style={styles.overlay} pointerEvents="box-none">
        {active ? (
          <>
            <View style={styles.reticle}>
              <View style={[styles.corner, styles.cornerTopLeft]} />
              <View style={[styles.corner, styles.cornerTopRight]} />
              <View style={[styles.corner, styles.cornerBottomLeft]} />
              <View style={[styles.corner, styles.cornerBottomRight]} />
            </View>
            <Text style={styles.hint}>Cadrez le code-barres du produit</Text>
          </>
        ) : null}

        {loading ? (
          <Card style={styles.card}>
            <Text style={styles.loadingText}>Vérification en cours</Text>
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
    backgroundColor: colors.ground,
  },
  title: { ...text.title, marginBottom: spacing.sm, textAlign: 'center' },
  description: { ...text.small, textAlign: 'center' },
  action: { alignSelf: 'stretch', marginTop: spacing.xl },
  overlay: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl },
  reticle: { width: '82%', maxWidth: 320, height: 170 },
  corner: { position: 'absolute', width: 26, height: 26, borderColor: '#FFFFFF' },
  cornerTopLeft: { top: 0, left: 0, borderTopWidth: 2, borderLeftWidth: 2 },
  cornerTopRight: { top: 0, right: 0, borderTopWidth: 2, borderRightWidth: 2 },
  cornerBottomLeft: { bottom: 0, left: 0, borderBottomWidth: 2, borderLeftWidth: 2 },
  cornerBottomRight: { bottom: 0, right: 0, borderBottomWidth: 2, borderRightWidth: 2 },
  hint: {
    ...text.label,
    color: '#FFFFFF',
    marginTop: spacing.xl,
    textShadowColor: 'rgba(0,0,0,0.7)',
    textShadowRadius: 6,
  },
  card: { width: '100%' },
  loadingText: { ...text.body, textAlign: 'center' },
  errorText: { ...text.body, color: thermal.critical.line },
  productName: text.heading,
  stockText: { ...text.small, marginTop: spacing.md },
  spaced: { marginTop: spacing.lg },
  footer: { position: 'absolute', bottom: spacing.xl, left: spacing.xl, right: spacing.xl },
});
