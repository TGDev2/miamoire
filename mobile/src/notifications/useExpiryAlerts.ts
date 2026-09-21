import { useCallback, useEffect } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { api } from '../api/client';
import { syncExpiryNotifications } from './expiry';

/**
 * Le garde est au niveau du module, pas du composant.
 *
 * Deux appelants existent — l'application au retour au premier plan, l'onglet
 * Stock à son focus — et ils tombent ensemble au cas le plus fréquent : on
 * rouvre l'application sur l'onglet Stock. Un garde par instance de hook les
 * laisserait tous deux partir et reprogrammerait deux fois la même chose.
 */
let inFlight: Promise<void> | null = null;

/** Quelqu'un a changé le stock après que la passe en vol l'a lu. */
let rerun = false;

/**
 * Reprogramme les alertes à partir de l'inventaire complet.
 *
 * La lecture part toujours du stock ENTIER, jamais de la liste affichée
 * (leçon nº 6) : un filtre d'écran ne doit pas annuler les alertes qu'il masque.
 * Un échec est silencieux — une alerte non programmée ne doit jamais empêcher
 * d'utiliser l'application.
 *
 * `fresh` est pour l'appelant qui vient lui-même de changer le stock — vider
 * l'inventaire, par exemple. Une passe déjà en vol a pu lire l'état d'avant :
 * absorbé par elle, l'appel laisserait programmées les alertes de lots qui
 * n'existent plus. Il demande donc une passe de plus, et la promesse ne se
 * résout qu'après elle. Les appels ordinaires — premier plan, focus — restent
 * dédoublonnés.
 */
export function resyncExpiryAlerts(options: { fresh?: boolean } = {}): Promise<void> {
  if (inFlight) {
    if (options.fresh) rerun = true;
    return inFlight;
  }

  inFlight = (async () => {
    try {
      do {
        rerun = false;

        try {
          const all = await api.stockItems();
          await syncExpiryNotifications(all.items);
        } catch {
          // Silence assumé : voir ci-dessus.
        }
      } while (rerun);
    } finally {
      inFlight = null;
    }
  })();

  return inFlight;
}

/**
 * Quand reprogrammer les alertes de péremption.
 *
 * Elles ne vivaient que dans le focus de l'onglet Stock. Or le stock est partagé :
 * une consommation faite depuis un autre téléphone laissait une alerte pour un
 * aliment déjà mangé, jusqu'au prochain passage sur cet onglet — c'est-à-dire
 * potentiellement jamais, puisque l'application s'ouvre aussi sur Cuisiner, sur
 * Profil, ou directement sur une fiche depuis une notification.
 *
 * Le moment où l'appareil peut apprendre ce que les autres ont fait est le retour
 * au premier plan. C'est donc là qu'on resynchronise. Ce hook ne prétend pas voir
 * un changement pendant que le téléphone dort : sans notifications serveur, cela
 * n'existe pas, et c'est écrit dans le README plutôt que suggéré par l'interface.
 */
export function useExpiryAlerts(enabled: boolean): void {
  const resync = useCallback(() => {
    if (enabled) void resyncExpiryAlerts();
  }, [enabled]);

  useEffect(() => {
    if (!enabled) return;

    resync();

    const subscription = AppState.addEventListener('change', (state: AppStateStatus) => {
      if (state === 'active') resync();
    });

    return () => subscription.remove();
  }, [enabled, resync]);
}
