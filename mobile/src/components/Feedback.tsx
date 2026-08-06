/**
 * Le canal de succès — celui qui manquait.
 *
 * L'interface partagée savait dire deux choses : « ça a échoué » (`ErrorBanner`)
 * et « ça charge » (`Loader`). Rien ne disait « c'est fait ». Un lot ajouté, un
 * yaourt consommé, un code-barres lu ne produisaient aucun accusé de réception :
 * l'écran changeait, et il fallait le croire.
 *
 * ## Pourquoi l'action part APRÈS la pastille, et non avant
 *
 * « Jeter » et « Tout consommer » sont irréversibles, et l'API n'a pas d'inverse
 * à leur opposer : on ne « dé-jette » pas un lot. Deux réponses classiques, et
 * pourquoi aucune ne convient — une confirmation avant chaque geste alourdit
 * précisément le parcours le plus fréquent de l'application ; une compensation
 * après coup demanderait au serveur un chemin qui n'existe pas.
 *
 * D'où le choix retenu : **la requête est retenue cinq secondes**. L'écran se met
 * à jour tout de suite, la barre du bas se vide à vue, et « Annuler » n'annule
 * pas une écriture — il l'empêche. Zéro tap ajouté, aucune invention côté
 * serveur, et un geste réellement réversible.
 *
 * Le seul mode de panne est de perdre l'action (application tuée pendant les cinq
 * secondes), jamais de la jouer deux fois : au pire l'aliment reste en stock.
 * C'est le bon sens de l'échec pour un inventaire. Le passage en arrière-plan
 * envoie d'ailleurs immédiatement ce qui est en attente.
 */

import { useSegments } from 'expo-router';
import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { AppState, Animated, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import * as haptics from '../haptics';
import { useReducedMotion } from '../motion';
import { colors, radius, spacing, text, thermal, type ThermalLevel } from '../theme';

/** Le délai de grâce. Assez long pour se raviser, assez court pour ne pas douter. */
const UNDO_MS = 5000;

/** Une pastille sans annulation ne fait qu'informer : elle n'a pas à s'attarder. */
const NOTICE_MS = 3200;

/** La barre d'onglets mesure sa hauteur elle-même ; on lui réserve de quoi passer. */
const TAB_BAR_CLEARANCE = 64;

export interface UndoableAction {
  /** Ce qui vient de se passer, au passé et nommé : « Yaourt nature — jeté ». */
  message: string;
  /** La requête, retenue cinq secondes. */
  commit: () => Promise<unknown>;
  /** Remettre la ligne quand la personne se ravise. */
  onUndo?: () => void;
  /** Remettre la ligne quand le serveur refuse — après coup, donc à dire. */
  onFailed?: (message: string) => void;
  tone?: ThermalLevel;
}

interface FeedbackApi {
  /** Une pastille qui informe et disparaît. */
  notify: (message: string, tone?: ThermalLevel) => void;
  /** Une pastille qui retient l'action le temps de se raviser. */
  commitWithUndo: (action: UndoableAction) => void;
}

const FeedbackContext = createContext<FeedbackApi | null>(null);

export function useFeedback(): FeedbackApi {
  const api = useContext(FeedbackContext);

  if (null === api) {
    throw new Error('useFeedback demande un FeedbackProvider au-dessus de lui.');
  }

  return api;
}

interface Toast {
  id: number;
  message: string;
  tone: ThermalLevel;
  undoable: boolean;
}

function errorMessage(exception: unknown): string {
  return exception instanceof Error ? exception.message : 'Enregistrement impossible.';
}

export function FeedbackProvider({ children }: { children: React.ReactNode }) {
  const [toast, setToast] = useState<Toast | null>(null);

  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  /** L'action retenue. Tant qu'elle est là, rien n'est parti au serveur. */
  const pending = useRef<UndoableAction | null>(null);
  const nextId = useRef(0);

  const clearTimer = useCallback(() => {
    if (null !== timer.current) {
      clearTimeout(timer.current);
      timer.current = null;
    }
  }, []);

  /** Envoie ce qui est retenu, tout de suite. Sans attendre la fin de la requête. */
  const flush = useCallback(() => {
    const action = pending.current;
    pending.current = null;
    clearTimer();

    if (null === action) return;

    void action
      .commit()
      .catch((exception) => {
        const message = errorMessage(exception);
        haptics.failure();
        action.onFailed?.(message);
        setToast({ id: nextId.current++, message, tone: 'critical', undoable: false });
      });
  }, [clearTimer]);

  const notify = useCallback(
    (message: string, tone: ThermalLevel = 'calm') => {
      // Une nouvelle pastille chasse la précédente : celle qui retenait une
      // action doit alors la libérer, sinon le geste serait perdu sans un mot.
      flush();
      setToast({ id: nextId.current++, message, tone, undoable: false });
      timer.current = setTimeout(() => setToast(null), NOTICE_MS);
    },
    [flush],
  );

  const commitWithUndo = useCallback(
    (action: UndoableAction) => {
      flush();
      pending.current = action;
      setToast({
        id: nextId.current++,
        message: action.message,
        tone: action.tone ?? 'calm',
        undoable: true,
      });
      timer.current = setTimeout(() => {
        flush();
        setToast(null);
      }, UNDO_MS);
    },
    [flush],
  );

  const undo = useCallback(() => {
    const action = pending.current;
    pending.current = null;
    clearTimer();
    setToast(null);
    haptics.tap();
    action?.onUndo?.();
  }, [clearTimer]);

  // Quitter l'application ne doit pas suspendre un geste engagé : on l'envoie.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if ('active' !== state) flush();
    });

    return () => {
      subscription.remove();
      flush();
    };
  }, [flush]);

  const api = useMemo(() => ({ notify, commitWithUndo }), [notify, commitWithUndo]);

  return (
    <FeedbackContext.Provider value={api}>
      <View style={styles.host}>
        {children}
        {null === toast ? null : <ToastView key={toast.id} toast={toast} onUndo={undo} />}
      </View>
    </FeedbackContext.Provider>
  );
}

function ToastView({ toast, onUndo }: { toast: Toast; onUndo: () => void }) {
  const insets = useSafeAreaInsets();
  const segments = useSegments();
  const reducedMotion = useReducedMotion();

  const entrance = useRef(new Animated.Value(0)).current;
  const drain = useRef(new Animated.Value(1)).current;

  // Les cinq onglets portent une barre en bas ; les écrans de pile, non. La
  // pastille ne doit couvrir la navigation dans aucun des deux cas.
  const overTabBar = segments.some((segment) => '(tabs)' === segment);

  useEffect(() => {
    entrance.setValue(reducedMotion ? 1 : 0);

    const animation = Animated.parallel([
      Animated.timing(entrance, { toValue: 1, duration: 180, useNativeDriver: true }),
      // Le compte à rebours est une information, pas un ornement : il se joue
      // même quand le système demande de réduire les animations.
      Animated.timing(drain, {
        toValue: 0,
        duration: toast.undoable ? UNDO_MS : NOTICE_MS,
        useNativeDriver: true,
      }),
    ]);

    animation.start();

    return () => animation.stop();
  }, [drain, entrance, reducedMotion, toast.id, toast.undoable]);

  const palette = thermal[toast.tone];

  return (
    <Animated.View
      pointerEvents="box-none"
      style={[
        styles.dock,
        { bottom: insets.bottom + (overTabBar ? TAB_BAR_CLEARANCE : spacing.lg) },
        {
          opacity: entrance,
          transform: [
            { translateY: entrance.interpolate({ inputRange: [0, 1], outputRange: [16, 0] }) },
          ],
        },
      ]}
    >
      <View
        style={styles.toast}
        accessible
        accessibilityLiveRegion="polite"
        accessibilityLabel={toast.message}
      >
        <View style={[styles.rail, { backgroundColor: palette.line }]} />

        <Text style={[text.subheading, styles.message]} numberOfLines={2}>
          {toast.message}
        </Text>

        {toast.undoable ? (
          <Pressable
            onPress={onUndo}
            hitSlop={8}
            accessibilityRole="button"
            accessibilityLabel="Annuler"
            accessibilityHint="Empêche l'action de partir"
            style={({ pressed }) => [styles.undo, pressed && styles.undoPressed]}
          >
            <Text style={[text.button, styles.undoLabel]}>Annuler</Text>
          </Pressable>
        ) : null}

        {/* Le temps qui reste, dessiné : la barre se vide d'un bord à l'autre.
            `scaleX` part sur le fil de rendu — elle ne saccade donc pas pendant
            que la requête libérée traverse le réseau. */}
        <Animated.View
          style={[
            styles.countdown,
            { backgroundColor: palette.line, transform: [{ scaleX: drain }] },
          ]}
          importantForAccessibility="no-hide-descendants"
        />
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  host: { flex: 1 },

  dock: { position: 'absolute', left: spacing.lg, right: spacing.lg },

  toast: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.borderStrong,
    paddingLeft: spacing.lg + spacing.xs,
    paddingRight: spacing.sm,
    paddingVertical: spacing.md,
    minHeight: 56,
    overflow: 'hidden',
  },
  rail: { position: 'absolute', left: 0, top: 0, bottom: 0, width: 3 },
  message: { flex: 1 },

  undo: {
    minHeight: 44,
    minWidth: 72,
    paddingHorizontal: spacing.md,
    borderRadius: radius.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  undoPressed: { backgroundColor: colors.surfaceSunk },
  undoLabel: { color: colors.ink },

  countdown: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    height: 2,
    opacity: 0.45,
    transformOrigin: 'left',
  },
});
