import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { api } from '../../src/api/client';
import { Button } from '../../src/components/ui';
import { useShoppingSession } from '../../src/shopping/ShoppingSessionContext';
import { colors, radius, spacing } from '../../src/theme';

/** Codes-barres réellement utilisés sur les emballages alimentaires. */
const BARCODE_TYPES = ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39'] as const;

/**
 * En-deçà de cette durée, une relecture du même code est le MÊME produit
 * encore dans le cadre (la caméra lit plusieurs fois par seconde tant qu'il y
 * reste) : elle est ignorée. Au-delà, c'est un geste délibéré — le produit a
 * été retiré puis représenté — et ça incrémente son nombre d'exemplaires.
 */
const REAPPEAR_GAP_MS = 1200;

export default function ScanScreen() {
  const router = useRouter();
  const [permission, requestPermission] = useCameraPermissions();
  const [active, setActive] = useState(true);
  const [feedback, setFeedback] = useState<string | null>(null);
  const { lines, addScannedLine, addManualLine } = useShoppingSession();

  const lastSeenAt = useRef(new Map<string, number>());
  const pendingLookups = useRef(new Set<string>());
  const feedbackTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useFocusEffect(
    useCallback(() => {
      setActive(true);

      return () => setActive(false);
    }, []),
  );

  function showFeedback(message: string) {
    setFeedback(message);
    if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
    feedbackTimer.current = setTimeout(() => setFeedback(null), 1200);
  }

  async function onScanned({ data }: { data: string }) {
    const now = Date.now();
    const last = lastSeenAt.current.get(data);
    lastSeenAt.current.set(data, now);

    if (last && now - last < REAPPEAR_GAP_MS) return;

    const alreadyInBasket = lines.some((line) => line.barcode === data);
    if (alreadyInBasket) {
      addScannedLine(data, { product: null, householdVariant: null });
      showFeedback('+1 exemplaire');
      return;
    }

    if (pendingLookups.current.has(data)) return;
    pendingLookups.current.add(data);

    try {
      const result = await api.lookupBarcode(data);
      addScannedLine(data, { product: result.product, householdVariant: result.householdVariant });
      showFeedback(result.householdVariant?.name ?? result.product.name);
    } catch {
      // Inconnu du catalogue (404) ou réseau indisponible : la ligne est
      // ajoutée quand même, à compléter manuellement à la validation.
      addScannedLine(data, { product: null, householdVariant: null });
      showFeedback('Produit à compléter');
    } finally {
      pendingLookups.current.delete(data);
    }
  }

  if (!permission) {
    return <View style={styles.centered} />;
  }

  if (!permission.granted) {
    return (
      <View style={styles.centered}>
        <Text style={styles.title}>Accès à la caméra</Text>
        <Text style={styles.description}>
          La caméra sert uniquement à lire les codes-barres des produits que vous ajoutez.
        </Text>
        <Button label="Autoriser la caméra" onPress={requestPermission} style={styles.action} />
        <Button
          label="Saisir un aliment à la main"
          variant="secondary"
          onPress={() => router.push('/add')}
          style={styles.action}
        />
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
        <View style={styles.reticle} />
        <Text style={styles.hint}>Cadrez le code-barres du produit</Text>
        {feedback ? (
          <View style={styles.feedback}>
            <Text style={styles.feedbackText}>✓ {feedback}</Text>
          </View>
        ) : null}

        <View style={styles.footer}>
          {lines.length > 0 ? (
            <Text style={styles.count}>
              {lines.length} produit{lines.length > 1 ? 's' : ''} scanné{lines.length > 1 ? 's' : ''}
            </Text>
          ) : null}
          <View style={styles.footerRow}>
            <Button
              label="Saisie manuelle"
              variant="secondary"
              onPress={() => {
                addManualLine();
                showFeedback('Ligne à compléter');
              }}
              style={styles.footerButton}
            />
            <Button
              label="Terminer"
              onPress={() => router.push('/shopping-review')}
              disabled={0 === lines.length}
              style={styles.footerButton}
            />
          </View>
        </View>
      </View>
    </View>
  );
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
  description: {
    fontSize: 15,
    color: colors.inkMuted,
    textAlign: 'center',
    lineHeight: 21,
    marginBottom: spacing.xl,
  },
  action: { alignSelf: 'stretch', marginBottom: spacing.md },
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
  feedback: {
    marginTop: spacing.lg,
    backgroundColor: 'rgba(47,107,79,0.9)',
    borderRadius: radius.pill,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
  },
  feedbackText: { color: '#FFFFFF', fontSize: 14, fontWeight: '700' },
  footer: { position: 'absolute', bottom: spacing.xl, left: spacing.xl, right: spacing.xl },
  count: {
    color: '#FFFFFF',
    fontSize: 14,
    fontWeight: '700',
    textAlign: 'center',
    marginBottom: spacing.md,
    textShadowColor: 'rgba(0,0,0,0.6)',
    textShadowRadius: 4,
  },
  footerRow: { flexDirection: 'row', gap: spacing.md },
  footerButton: { flex: 1 },
});
