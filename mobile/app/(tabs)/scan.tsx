import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { api } from '../../src/api/client';
import { Button, Icon } from '../../src/components/ui';
import { useShoppingSession } from '../../src/shopping/ShoppingSessionContext';
import { colors, font, radius, spacing, text } from '../../src/theme';

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
  const insets = useSafeAreaInsets();
  const [permission, requestPermission] = useCameraPermissions();
  const [active, setActive] = useState(true);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [listCount, setListCount] = useState(0);
  const { lines, addScannedLine, addManualLine } = useShoppingSession();

  const lastSeenAt = useRef(new Map<string, number>());
  const pendingLookups = useRef(new Set<string>());
  const feedbackTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useFocusEffect(
    useCallback(() => {
      setActive(true);
      api
        .shoppingList()
        .then((response) => setListCount(response.items.length))
        .catch(() => undefined);

      return () => setActive(false);
    }, []),
  );

  useEffect(() => {
    return () => {
      if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
    };
  }, []);

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
      addScannedLine(data, {
        product: result.product,
        householdVariant: result.householdVariant,
        shoppingListItem: result.shoppingListItem,
      });
      showFeedback(
        result.shoppingListItem
          ? `Sur votre liste : ${result.shoppingListItem.label}`
          : (result.householdVariant?.name ?? result.product.name),
      );
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
    return <View style={styles.permission} />;
  }

  if (!permission.granted) {
    return (
      <View style={[styles.permission, { paddingTop: insets.top + spacing.xxl }]}>
        <View style={styles.permissionIcon}>
          <Icon name="camera-outline" size={22} color={colors.inkMuted} />
        </View>
        <Text style={[text.title, styles.permissionTitle]}>Autoriser la caméra</Text>
        <Text style={[text.small, styles.permissionText]}>
          Elle ne sert qu'à lire les codes-barres des produits que vous ajoutez. Aucune image n'est
          enregistrée ni envoyée.
        </Text>
        <Button label="Autoriser la caméra" onPress={requestPermission} style={styles.permissionAction} />
        <Button
          label="Saisir un aliment à la main"
          variant="secondary"
          onPress={() => router.push('/add')}
          style={styles.permissionAction}
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

      <View style={[styles.topBar, { paddingTop: insets.top + spacing.sm }]} pointerEvents="box-none">
        <Text style={styles.topLabel}>Retour de courses</Text>
        <Pressable
          onPress={() => router.push('/shopping-list')}
          accessibilityRole="button"
          accessibilityLabel={`Liste de courses, ${listCount} article${listCount > 1 ? 's' : ''}`}
          style={({ pressed }) => [styles.listButton, pressed && styles.listButtonPressed]}
        >
          <Icon name="format-list-checks" size={16} color={colors.ink} />
          <Text style={styles.listButtonText}>{listCount > 0 ? `Liste · ${listCount}` : 'Liste'}</Text>
        </Pressable>
      </View>

      <View style={styles.overlay} pointerEvents="box-none">
        {/* Quatre équerres plutôt qu'un cadre plein : le code reste entièrement
            visible pendant qu'on le cadre. */}
        <View style={styles.reticle}>
          <View style={[styles.corner, styles.cornerTopLeft]} />
          <View style={[styles.corner, styles.cornerTopRight]} />
          <View style={[styles.corner, styles.cornerBottomLeft]} />
          <View style={[styles.corner, styles.cornerBottomRight]} />
        </View>

        <Text style={styles.hint}>Cadrez le code-barres</Text>

        {feedback ? (
          <View style={styles.feedback} accessibilityLiveRegion="polite">
            <Icon name="check" size={16} color={colors.ink} />
            <Text style={styles.feedbackText} numberOfLines={1}>
              {feedback}
            </Text>
          </View>
        ) : null}
      </View>

      <View style={[styles.footer, { paddingBottom: spacing.lg + insets.bottom }]}>
        <Text style={styles.count}>
          {0 === lines.length
            ? 'Aucun produit scanné'
            : `${lines.length} produit${lines.length > 1 ? 's' : ''} dans le panier`}
        </Text>
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
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: '#000000' },
  paused: { backgroundColor: '#000000' },

  permission: {
    flex: 1,
    alignItems: 'center',
    padding: spacing.xl,
    backgroundColor: colors.ground,
  },
  permissionIcon: {
    width: 48,
    height: 48,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  permissionTitle: { marginTop: spacing.lg, textAlign: 'center' },
  permissionText: { marginTop: spacing.sm, textAlign: 'center', maxWidth: 320 },
  permissionAction: { alignSelf: 'stretch', marginTop: spacing.md },

  topBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.sm,
  },
  topLabel: {
    ...text.label,
    color: '#FFFFFF',
    textShadowColor: 'rgba(0,0,0,0.7)',
    textShadowRadius: 6,
  },
  listButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs + 2,
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.md,
    minHeight: 36,
  },
  listButtonPressed: { backgroundColor: colors.surfaceSunk },
  listButtonText: { fontFamily: font.monoMedium, fontSize: 11, color: colors.ink },

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
  feedback: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    marginTop: spacing.lg,
    maxWidth: '90%',
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  feedbackText: { ...text.subheading, flexShrink: 1 },

  /**
   * La barre de commande est une surface blanche, comme toutes les autres :
   * sur un voile sombre, le bouton principal en encre disparaissait dans le
   * fond, et l'état inactif encore plus.
   */
  footer: {
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.lg,
    backgroundColor: colors.surface,
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  count: { ...text.label, textAlign: 'center', marginBottom: spacing.md },
  footerRow: { flexDirection: 'row', gap: spacing.sm },
  footerButton: { flex: 1 },
});
