import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { Button } from '../../src/components/ui';
import { colors, radius, spacing } from '../../src/theme';

/** Codes-barres réellement utilisés sur les emballages alimentaires. */
const BARCODE_TYPES = ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39'] as const;

export default function ScanScreen() {
  const router = useRouter();
  const [permission, requestPermission] = useCameraPermissions();
  const [active, setActive] = useState(true);
  // La caméra émet plusieurs lectures par seconde : on ne retient que la première.
  const handled = useRef(false);

  useFocusEffect(
    useCallback(() => {
      handled.current = false;
      setActive(true);

      return () => setActive(false);
    }, []),
  );

  function onScanned({ data }: { data: string }) {
    if (handled.current) return;
    handled.current = true;
    setActive(false);
    router.push({ pathname: '/add', params: { barcode: data } });
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
          onBarcodeScanned={onScanned}
        />
      ) : (
        <View style={[StyleSheet.absoluteFill, styles.paused]} />
      )}

      <View style={styles.overlay} pointerEvents="box-none">
        <View style={styles.reticle} />
        <Text style={styles.hint}>Cadrez le code-barres du produit</Text>

        <View style={styles.footer}>
          <Button
            label="Saisie manuelle"
            variant="secondary"
            onPress={() => router.push('/add')}
          />
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
  footer: { position: 'absolute', bottom: spacing.xl, left: spacing.xl, right: spacing.xl },
});
