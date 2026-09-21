import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api } from '../../src/api/client';
import { Button, Icon } from '../../src/components/ui';
import { RETAIL_BARCODE_TYPES, readRetailBarcode } from '../../src/scan/retailBarcode';
import { createScanGate } from '../../src/scan/scanGate';
import { useShoppingSession } from '../../src/shopping/ShoppingSessionContext';
import { colors, font, radius, spacing, text, thermal } from '../../src/theme';

/**
 * Pendant les courses, on regarde les produits, pas l'écran : un échec doit
 * rester lisible bien plus longtemps qu'une confirmation, sans quoi le
 * produit manquerait au panier sans que rien ne l'ait signalé.
 */
const FEEDBACK_MS = 1200;
const FAILURE_FEEDBACK_MS = 4000;

type Feedback = { message: string; failed: boolean };

export default function ScanScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const [permission, requestPermission] = useCameraPermissions();
  const [active, setActive] = useState(true);
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [listCount, setListCount] = useState(0);
  const { lines, addScannedLine, addManualLine } = useShoppingSession();

  /** Un produit compte une fois par présentation devant la caméra — voir `scanGate`. */
  const scanGate = useRef(createScanGate());
  const pendingLookups = useRef(new Set<string>());
  const feedbackTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useFocusEffect(
    useCallback(() => {
      setActive(true);
      api
        .shoppingList()
        .then((response) => setListCount(response.items.length))
        // Le compte échouait en silence et le bouton affichait « Liste » tout
        // court — indistinguable d'une liste vide. On dit désormais qu'on ne
        // sait pas plutôt que de laisser croire qu'il n'y a rien à acheter.
        .catch(() => setListCount(null));

      return () => {
        setActive(false);
        setTorch(false);
      };
    }, []),
  );

  useEffect(() => {
    return () => {
      if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
    };
  }, []);

  function showFeedback(message: string, failed = false) {
    setFeedback({ message, failed });
    if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
    feedbackTimer.current = setTimeout(() => setFeedback(null), failed ? FAILURE_FEEDBACK_MS : FEEDBACK_MS);
  }

  async function onScanned(scan: { type: string; data: string }) {
    // Une lecture qui n'est pas un code alimentaire valide n'a rien scanné :
    // elle ne doit ni ajouter de ligne, ni retarder la lecture suivante.
    const data = readRetailBarcode(scan);
    if (null === data || !scanGate.current(data, Date.now())) return;

    const existing = lines.find((line) => line.barcode === data);
    if (existing) {
      addScannedLine(data, { product: null, householdVariant: null });
      haptics.tap();
      // « +1 exemplaire » ne disait pas de quoi : scanner le mauvais article
      // deux fois passait inaperçu.
      showFeedback(`${existing.name} · ${existing.numberOfPackages + 1} exemplaires`);
      setLastScan({ barcode: data, label: existing.name });
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

      const label = result.householdVariant?.name ?? result.product.name;
      haptics.success();
      showFeedback(
        result.shoppingListItem
          ? `Sur votre liste : ${result.shoppingListItem.label}`
          : label,
      );
    } catch (error) {
      if (error instanceof ApiError && 404 === error.status) {
        // Un code valide qu'Open Food Facts ne connaît pas : la ligne est
        // ajoutée, à nommer à la validation.
        addScannedLine(data, { product: null, householdVariant: null });
        showFeedback('Produit à compléter');
      } else if (!(error instanceof ApiError && 401 === error.status)) {
        // Réseau, serveur ou Open Food Facts muets : on ignore ce qu'est ce
        // produit. Une ligne vide ferait ressaisir ce que le catalogue connaît
        // sans doute ; le représenter la retrouvera préremplie. (Un 401 est
        // déjà pris en charge par la session.)
        showFeedback('Recherche impossible, rescannez', true);
      }
    } finally {
      pendingLookups.current.delete(data);
    }
  }

  function undoLastScan() {
    if (null === lastScan) return;

    haptics.removed();
    undoScan(lastScan.barcode);
    // La fenêtre anti-doublon garde une trace du code : sans cet oubli, le
    // représenter aussitôt pour se corriger ne ferait rien.
    lastSeenAt.current.delete(lastScan.barcode);
    showFeedback(`${lastScan.label} — retiré du panier`);
    setLastScan(null);
  }

  // Un `View` vide était indistinguable d'un plantage, même pour une fraction
  // de seconde, sur un écran dont on attend qu'il montre une image.
  if (!permission) {
    return <Loader label="Vérification de l'autorisation caméra" />;
  }

  if (!permission.granted) {
    // Après un second refus sur Android, `requestPermission()` se résout sans
    // rien afficher : le bouton ne produisait littéralement rien, et l'écran
    // était un cul-de-sac. Le réglage système est alors le seul chemin.
    const settingsOnly = !permission.canAskAgain;

    return (
      <View style={[styles.permission, { paddingTop: insets.top + spacing.xxl }]}>
        <View style={styles.permissionIcon}>
          <Icon name="camera-outline" size={22} color={colors.inkMuted} />
        </View>
        <Text style={[text.title, styles.permissionTitle]}>Autoriser la caméra</Text>
        <Text style={[text.small, styles.permissionText]}>
          {settingsOnly
            ? 'L\'autorisation a été refusée pour cette application. Elle se réactive depuis les réglages du téléphone.'
            : 'Elle ne sert qu\'à lire les codes-barres des produits que vous ajoutez. Aucune image n\'est enregistrée ni envoyée.'}
        </Text>
        <Button
          label={settingsOnly ? 'Ouvrir les réglages' : 'Autoriser la caméra'}
          icon={settingsOnly ? 'cog-outline' : undefined}
          onPress={() => {
            if (settingsOnly) void Linking.openSettings();
            else void requestPermission();
          }}
          style={styles.permissionAction}
        />
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
          barcodeScannerSettings={{ barcodeTypes: [...RETAIL_BARCODE_TYPES] }}
          onBarcodeScanned={(event) => void onScanned(event)}
        />
      ) : (
        // Revenir sur l'onglet remonte la caméra à froid : plusieurs centaines
        // de millisecondes de noir, sur un écran qui EST une caméra. Sans un
        // mot, on croit l'application figée.
        <View style={[StyleSheet.absoluteFill, styles.paused]}>
          <Text style={styles.pausedText}>Caméra en pause</Text>
        </View>
      )}

      <View style={[styles.topBar, { paddingTop: insets.top + spacing.sm }]} pointerEvents="box-none">
        <Text style={styles.topLabel}>Retour de courses</Text>

        <View style={styles.topActions}>
          {/* Les codes-barres se lisent souvent dans un placard, un coffre ou
              une réserve : la torche fait partie du geste. */}
          <Pressable
            onPress={() => {
              haptics.select();
              setTorch((current) => !current);
            }}
            accessibilityRole="button"
            accessibilityLabel={torch ? 'Éteindre la torche' : 'Allumer la torche'}
            accessibilityState={{ selected: torch }}
            style={({ pressed }) => [
              styles.topButton,
              torch && styles.topButtonOn,
              pressed && styles.listButtonPressed,
            ]}
          >
            <Icon
              name={torch ? 'flashlight' : 'flashlight-off'}
              size={18}
              color={torch ? colors.onInk : colors.ink}
            />
          </Pressable>

          <Pressable
            onPress={() => router.push('/shopping-list')}
            accessibilityRole="button"
            accessibilityLabel={
              null === listCount
                ? 'Liste de courses, nombre inconnu'
                : `Liste de courses, ${listCount} article${listCount > 1 ? 's' : ''}`
            }
            style={({ pressed }) => [styles.listButton, pressed && styles.listButtonPressed]}
          >
            <Icon name="format-list-checks" size={16} color={colors.ink} />
            <Text style={styles.listButtonText}>
              {null !== listCount && listCount > 0 ? `Liste · ${listCount}` : 'Liste'}
            </Text>
          </Pressable>
        </View>
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
            <Icon name={feedback.failed ? 'alert-outline' : 'check'} size={16} color={colors.ink} />
            <Text style={styles.feedbackText} numberOfLines={1}>
              {feedback.message}
            </Text>
          </View>
        ) : null}
      </View>

      <View style={[styles.footer, { paddingBottom: spacing.lg + insets.bottom }]}>
        <View style={styles.countRow}>
          <Text style={styles.count}>
            {0 === lines.length
              ? 'Aucun produit scanné'
              : `${lines.length} produit${lines.length > 1 ? 's' : ''} dans le panier`}
          </Text>
          {/* L'erreur la plus probable du parcours — le mauvais article, ou
              deux fois le même — ne se réparait qu'après « Terminer », deux
              navigations plus loin. */}
          {null === lastScan ? null : (
            <Pressable
              onPress={undoLastScan}
              hitSlop={8}
              accessibilityRole="button"
              accessibilityLabel={`Annuler le dernier scan, ${lastScan.label}`}
              style={({ pressed }) => [styles.undo, pressed && styles.listButtonPressed]}
            >
              <Icon name="undo-variant" size={16} color={thermal.critical.line} />
              <Text style={styles.undoText}>Annuler</Text>
            </Pressable>
          )}
        </View>
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
  paused: { backgroundColor: '#000000', alignItems: 'center', justifyContent: 'center' },
  pausedText: { ...text.label, color: '#8B9AA3' },

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
  topActions: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm },
  topButton: {
    width: 44,
    height: 44,
    borderRadius: radius.sm,
    backgroundColor: colors.surface,
    alignItems: 'center',
    justifyContent: 'center',
  },
  topButtonOn: { backgroundColor: colors.ink },
  listButton: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs + 2,
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    paddingHorizontal: spacing.md,
    // 36 px sur le seul lien vers la liste de courses depuis cet écran, dans la
    // zone la moins atteignable au pouce.
    minHeight: 44,
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
  countRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.md,
    marginBottom: spacing.md,
    minHeight: 32,
  },
  count: { ...text.label },
  undo: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs,
    minHeight: 32,
    paddingHorizontal: spacing.sm,
    borderRadius: radius.sm,
  },
  undoText: { ...text.label, color: thermal.critical.line },
  footerRow: { flexDirection: 'row', gap: spacing.sm },
  footerButton: { flex: 1 },
});
