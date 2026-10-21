import { CameraView, useCameraPermissions } from 'expo-camera';
import { useFocusEffect, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Image, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ApiError, api } from '../src/api/client';
import { useFeedback } from '../src/components/Feedback';
import { Button, Card, ErrorBanner, Icon, Loader } from '../src/components/ui';
import * as haptics from '../src/haptics';
import { useShoppingSession } from '../src/shopping/ShoppingSessionContext';
import { groupLines } from '../src/shopping/triage';
import { colors, font, radius, spacing, text, thermal } from '../src/theme';

/** A long receipt does not fit one frame: up to three photos, read as one. */
const MAX_PHOTOS = 3;

/**
 * Text, not a barcode: the model must read every character of a thermal
 * print. Six tenths keeps a phone photo sharp and well under the server's
 * 8 MB without resizing it — the one thing that would blur the small print.
 */
const PHOTO_QUALITY = 0.6;

interface Photo {
  uri: string;
  width: number;
  height: number;
  /** The JPEG bytes, base64: what the server reads. */
  base64: string;
}

/**
 * Le ticket de caisse, lu en une fois.
 *
 * One photo instead of one scan per product: the server reads the lines,
 * resolves each as a basket line, and sets aside what is not food. The lines
 * land in the basket already resolved, and the triage shows what came in.
 */
export default function ReceiptScreen() {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { notify } = useFeedback();
  const { addReceiptReading } = useShoppingSession();
  const [permission, requestPermission] = useCameraPermissions();

  /** The camera is mounted only while the screen is in front, like the scanner. */
  const [active, setActive] = useState(true);
  const [cameraReady, setCameraReady] = useState(false);
  const [photos, setPhotos] = useState<Photo[]>([]);
  /** The camera is up: before the first photo, and after « Autre photo ». */
  const [framing, setFraming] = useState(true);
  const [capturing, setCapturing] = useState(false);
  const [reading, setReading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const camera = useRef<CameraView>(null);
  /** The reading in flight: « Annuler » and leaving the screen abort it. */
  const controller = useRef<AbortController | null>(null);

  useFocusEffect(
    useCallback(() => {
      setActive(true);

      return () => {
        setActive(false);
        setCameraReady(false);
      };
    }, []),
  );

  useEffect(() => () => controller.current?.abort(), []);

  const count = photos.length;
  const plural = count > 1 ? 's' : '';
  const showCamera = active && !reading && count < MAX_PHOTOS && (framing || 0 === count);

  function openCamera() {
    setCameraReady(false);
    setFraming(true);
  }

  async function capture() {
    const view = camera.current;
    if (!view || capturing) return;

    setCapturing(true);
    setError(null);
    try {
      // Base64 from the camera itself: the app's `fetch` cannot attach a file
      // to a form, so the photo travels inside a JSON body.
      const picture = await view.takePictureAsync({ quality: PHOTO_QUALITY, skipProcessing: false, base64: true });
      if (!picture.base64) throw new Error('No image data');
      haptics.tap();
      setPhotos((current) => [
        ...current,
        { uri: picture.uri, width: picture.width, height: picture.height, base64: picture.base64 as string },
      ]);
      setCameraReady(false);
      setFraming(false);
    } catch {
      haptics.failure();
      setError("La photo n'a pas pu être prise. Réessayez.");
    } finally {
      setCapturing(false);
    }
  }

  function removePhoto(index: number) {
    haptics.removed();
    setCameraReady(false);
    setPhotos((current) => current.filter((unused, position) => position !== index));
  }

  /** After an unreadable answer: the last photo goes, the camera comes back for a better one. */
  function retakeLast() {
    setError(null);
    setPhotos((current) => current.slice(0, -1));
    openCamera();
  }

  async function read() {
    if (0 === count || reading) return;

    const own = new AbortController();
    controller.current = own;
    setReading(true);
    setError(null);
    haptics.tap();

    try {
      const reading = await api.readReceipt(
        photos.map((photo) => ({ mediaType: 'image/jpeg', data: photo.base64 })),
        { signal: own.signal },
      );
      if (own.signal.aborted) return;

      const added = addReceiptReading(reading);
      if (0 === added.length && 0 === reading.ignored.length) {
        haptics.failure();
        setError("Aucune ligne d'article n'a été lue sur ce ticket. Reprenez la photo de plus près, le ticket bien à plat.");
        return;
      }

      const { toDecide, ready } = groupLines(added);
      haptics.success();
      notify(summary(added.length, ready.length, toDecide.length), 'safe');
      router.replace('/shopping-review');
    } catch (exception) {
      // A cancellation is a gesture, not a failure: nothing to say.
      if (exception instanceof ApiError && exception.isAborted) return;

      haptics.failure();
      setError(exception instanceof Error ? exception.message : 'Lecture du ticket impossible.');
    } finally {
      if (controller.current === own) controller.current = null;
      setReading(false);
    }
  }

  function cancel() {
    controller.current?.abort();
  }

  if (!permission) {
    return <Loader label="Vérification de l'autorisation caméra" />;
  }

  if (!permission.granted) {
    // Après un second refus sur Android, `requestPermission()` se résout sans
    // rien afficher : le réglage système est alors le seul chemin (voir `scan.tsx`).
    const settingsOnly = !permission.canAskAgain;

    return (
      <View style={[styles.permission, { paddingTop: spacing.xxl }]}>
        <View style={styles.permissionIcon}>
          <Icon name="camera-outline" size={22} color={colors.inkMuted} />
        </View>
        <Text style={[text.title, styles.permissionTitle]}>Autoriser la caméra</Text>
        <Text style={[text.small, styles.permissionText]}>
          {settingsOnly
            ? "L'autorisation a été refusée pour cette application. Elle se réactive depuis les réglages du téléphone."
            : 'Elle sert ici à photographier le ticket de caisse. La photo est lue par le service de recettes puis oubliée.'}
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
        <Button label="Revenir en arrière" variant="secondary" onPress={() => router.back()} style={styles.permissionAction} />
      </View>
    );
  }

  return (
    <ScrollView
      contentContainerStyle={[styles.container, { paddingBottom: spacing.xl + insets.bottom }]}
      keyboardShouldPersistTaps="handled"
    >
      <Text style={text.body}>
        Demandez le ticket en caisse, ou photographiez le ticket dématérialisé. Jusqu'à 3 photos pour un long
        ticket.
      </Text>

      <View style={styles.banner}>
        <ErrorBanner message={error} />
      </View>

      {reading ? (
        <Card level="safe" style={styles.stateCard}>
          <Loader label="Lecture du ticket…" />
          <Text style={[text.small, styles.stateHint]}>
            Entre cinq et quarante secondes : le modèle lit chaque ligne du ticket.
          </Text>
          <Button label="Annuler" variant="ghost" onPress={cancel} style={styles.stateAction} />
        </Card>
      ) : null}

      {showCamera ? (
        <View style={styles.framing}>
          <CameraView ref={camera} style={styles.camera} facing="back" onCameraReady={() => setCameraReady(true)} />
          <Text style={styles.cameraHint}>Ticket à plat, bien éclairé, le texte net</Text>
          <Button
            label={count > 0 ? `Prendre la photo ${count + 1}` : 'Prendre la photo'}
            icon="camera"
            onPress={() => void capture()}
            loading={capturing}
            disabled={!cameraReady}
            style={styles.spaced}
          />
          {count > 0 ? (
            <Button label="Garder les photos prises" variant="ghost" onPress={() => setFraming(false)} style={styles.spaced} />
          ) : null}
        </View>
      ) : null}

      {count > 0 ? (
        <>
          <Text style={styles.photosLabel}>{`${count} photo${plural} sur ${MAX_PHOTOS}`}</Text>
          <View style={styles.thumbnails}>
            {photos.map((photo, index) => (
              <View key={photo.uri} style={styles.thumbnail}>
                <Image
                  source={{ uri: photo.uri }}
                  style={styles.thumbnailImage}
                  resizeMode="cover"
                  accessibilityLabel={`Photo ${index + 1} du ticket`}
                />
                <Pressable
                  onPress={() => removePhoto(index)}
                  disabled={reading}
                  accessibilityRole="button"
                  accessibilityLabel={`Retirer la photo ${index + 1}`}
                  accessibilityState={{ disabled: reading }}
                  style={({ pressed }) => [styles.remove, pressed && styles.removePressed]}
                >
                  <Text style={styles.removeText}>Retirer</Text>
                </Pressable>
              </View>
            ))}
          </View>

          {!reading ? (
            <>
              {error && !showCamera ? (
                <Button
                  label="Reprendre la photo"
                  variant="secondary"
                  icon="camera-retake-outline"
                  onPress={retakeLast}
                  hint="Remplace la dernière photo"
                  style={styles.spaced}
                />
              ) : !showCamera && count < MAX_PHOTOS ? (
                <Button
                  label="Autre photo"
                  variant="secondary"
                  icon="camera-plus-outline"
                  onPress={openCamera}
                  hint="Pour la suite d'un long ticket"
                  style={styles.spaced}
                />
              ) : null}
              <Button
                label={`${error ? 'Réessayer' : 'Lire le ticket'} (${count} photo${plural})`}
                icon="text-recognition"
                onPress={() => void read()}
                style={styles.spaced}
              />
            </>
          ) : null}
        </>
      ) : null}

      <Text style={[text.small, styles.privacy]}>
        La photo est lue par le service de recettes puis oubliée ; seules les lignes d'articles sont gardées.
      </Text>
    </ScrollView>
  );
}

/** « Ticket lu : 12 lignes, 9 rangées d'avance, 3 à préciser ». */
function summary(total: number, ready: number, pending: number): string {
  const s = (value: number) => (value > 1 ? 's' : '');

  return `Ticket lu : ${total} ligne${s(total)}, ${ready} rangée${s(ready)} d'avance, ${pending} à préciser`;
}

const styles = StyleSheet.create({
  container: { padding: spacing.xl, flexGrow: 1 },
  banner: { marginTop: spacing.md },
  spaced: { marginTop: spacing.md },

  stateCard: { marginTop: spacing.md },
  stateHint: { textAlign: 'center', marginTop: spacing.sm },
  stateAction: { marginTop: spacing.md },

  framing: { marginTop: spacing.lg },
  /** Portrait, like a receipt: more lines per photo than a landscape frame. */
  camera: {
    width: '100%',
    aspectRatio: 3 / 4,
    borderRadius: radius.lg,
    overflow: 'hidden',
    backgroundColor: '#000000',
  },
  cameraHint: { ...text.label, textAlign: 'center', marginTop: spacing.md },

  photosLabel: { ...text.label, marginTop: spacing.xl, marginBottom: spacing.sm },
  thumbnails: { flexDirection: 'row', gap: spacing.md },
  thumbnail: { width: 96 },
  thumbnailImage: {
    width: 96,
    aspectRatio: 3 / 4,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surfaceSunk,
  },
  remove: { minHeight: 44, alignItems: 'center', justifyContent: 'center', borderRadius: radius.sm },
  removePressed: { backgroundColor: colors.surfaceSunk },
  removeText: { ...text.small, color: thermal.critical.line, fontFamily: font.sansMedium },

  privacy: { textAlign: 'center', marginTop: spacing.lg },

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
});
