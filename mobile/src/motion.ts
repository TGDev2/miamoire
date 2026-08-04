import { useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated } from 'react-native';

/**
 * « Réduire les animations » est un réglage système : le respecter n'est pas
 * une option, et il peut changer pendant que l'application tourne.
 */
export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false);

  useEffect(() => {
    let cancelled = false;

    void AccessibilityInfo.isReduceMotionEnabled().then((value) => {
      if (!cancelled) setReduced(value);
    });

    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduced);

    return () => {
      cancelled = true;
      subscription.remove();
    };
  }, []);

  return reduced;
}

/**
 * L'unique moment orchestré de l'application : l'arrivée en cascade des lots à
 * traiter, sur « Aujourd'hui ». Elle dit quelque chose de vrai — les urgences
 * se présentent dans leur ordre de priorité — et ne se répète nulle part
 * ailleurs. Le reste de l'interface ne bouge pas.
 *
 * `useNativeDriver` : l'opacité et la translation partent sur le fil de rendu,
 * donc la cascade ne saccade pas pendant que les trois requêtes se terminent.
 */
export function useEnterAnimation(delay: number, enabled: boolean) {
  const progress = useRef(new Animated.Value(enabled ? 0 : 1)).current;

  useEffect(() => {
    if (!enabled) {
      progress.setValue(1);
      return;
    }

    const animation = Animated.timing(progress, {
      toValue: 1,
      duration: 220,
      delay,
      useNativeDriver: true,
    });

    animation.start();

    return () => animation.stop();
  }, [delay, enabled, progress]);

  return {
    opacity: progress,
    transform: [{ translateY: progress.interpolate({ inputRange: [0, 1], outputRange: [10, 0] }) }],
  };
}
