import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

import type { StockItem } from '../api/types';

/** Trois jours : de quoi cuisiner le produit avant qu'il ne soit perdu. */
export const ALERT_DAYS_BEFORE = 3;

/** Heure de l'alerte : le matin, quand on décide encore des repas de la journée. */
const ALERT_HOUR = 9;

const CHANNEL_ID = 'expiry';

/**
 * Préfixe d'identifiant : il permet de retrouver et d'annuler nos propres
 * notifications sans toucher à celles qu'une autre partie de l'application
 * programmerait un jour.
 */
const PREFIX = 'expiry:';

/**
 * Alertes de péremption, programmées sur le téléphone.
 *
 * Rien ne transite par le serveur : chaque appareil programme ses propres
 * notifications à partir du stock qu'il vient d'afficher. C'est ce qui les rend
 * fiables hors connexion, et ce qui évite d'avoir à gérer des jetons d'appareil.
 *
 * Limite assumée : un lot enregistré alors qu'il périme dans moins de trois jours
 * n'a pas d'alerte — l'instant est déjà passé. Il apparaît en revanche d'emblée
 * dans la liste et sous le filtre « Bientôt périmés ».
 */
export async function ensureExpiryAlerts(): Promise<boolean> {
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync(CHANNEL_ID, {
      name: 'Péremption',
      importance: Notifications.AndroidImportance.DEFAULT,
      description: 'Rappel trois jours avant la date limite de consommation.',
    });
  }

  const current = await Notifications.getPermissionsAsync();
  if (current.granted) return true;

  // Ne redemande pas si la personne a explicitement refusé : Android ne
  // réafficherait de toute façon plus la demande.
  if (!current.canAskAgain) return false;

  const requested = await Notifications.requestPermissionsAsync();

  return requested.granted;
}

/**
 * Reprogramme entièrement les alertes à partir du stock courant.
 *
 * On annule puis on reprogramme plutôt que de tenir un état local : le stock
 * change depuis plusieurs écrans et plusieurs téléphones, et un état parallèle
 * finirait par mentir. Ici, l'inventaire est la seule source de vérité.
 */
export async function syncExpiryNotifications(items: StockItem[]): Promise<void> {
  const granted = await ensureExpiryAlerts();
  if (!granted) return;

  const scheduled = await Notifications.getAllScheduledNotificationsAsync();
  await Promise.all(
    scheduled
      .filter((entry) => entry.identifier.startsWith(PREFIX))
      .map((entry) => Notifications.cancelScheduledNotificationAsync(entry.identifier)),
  );

  const now = Date.now();

  await Promise.all(
    items.map(async (item) => {
      const alertDate = alertDateFor(item);
      if (alertDate === null || alertDate.getTime() <= now) return;

      await Notifications.scheduleNotificationAsync({
        identifier: `${PREFIX}${item.id}`,
        content: {
          title: titleFor(item),
          body: bodyFor(item),
          data: { stockItemId: item.id },
        },
        trigger: {
          type: Notifications.SchedulableTriggerInputTypes.DATE,
          date: alertDate,
          ...(Platform.OS === 'android' ? { channelId: CHANNEL_ID } : {}),
        },
      });
    }),
  );
}

/** Retire toute alerte programmée — au changement de foyer, par exemple. */
export async function clearExpiryNotifications(): Promise<void> {
  const scheduled = await Notifications.getAllScheduledNotificationsAsync();

  await Promise.all(
    scheduled
      .filter((entry) => entry.identifier.startsWith(PREFIX))
      .map((entry) => Notifications.cancelScheduledNotificationAsync(entry.identifier)),
  );
}

/**
 * `null` quand le lot n'a pas d'échéance effective ou qu'il est déjà épuisé.
 *
 * Fondée sur `effectiveUseBy` — la plus proche de la date imprimée/estimée et
 * de la durée de conservation après ouverture — et non sur la seule
 * `expirationDate` : un lot entamé peut se dégrader plus vite que sa date
 * imprimée ne le dit, et l'alerte doit alors arriver plus tôt, pas plus tard.
 */
function alertDateFor(item: StockItem): Date | null {
  if (item.effectiveUseBy === null || item.remainingQuantity <= 0) return null;

  // « 2026-07-29 » interprété localement : `new Date('2026-07-29')` serait en UTC
  // et décalerait l'alerte d'un jour selon le fuseau.
  const [year, month, day] = item.effectiveUseBy.split('-').map(Number);
  if (!year || !month || !day) return null;

  return new Date(year, month - 1, day - ALERT_DAYS_BEFORE, ALERT_HOUR, 0, 0, 0);
}

function titleFor(item: StockItem): string {
  switch (item.effectiveUseByBasis) {
    case 'estimated':
      return `${item.variant.name} est à vérifier`;
    case 'after_opening':
      return `${item.variant.name}, entamé, périme bientôt`;
    default:
      return `${item.variant.name} périme bientôt`;
  }
}

/**
 * Une date déduite d'une durée de conservation ne s'annonce pas comme une date
 * lue sur un emballage : « avant le 2 août » ferait jeter une salade encore bonne.
 * Une date issue de la durée après ouverture le dit tout autant : ce n'est pas
 * ce qui est imprimé sur l'emballage, mais ce que l'ouverture a changé.
 */
function bodyFor(item: StockItem): string {
  const quantity = `${item.displayQuantity} ${item.displayUnit}`;
  const date = item.effectiveUseBy
    ? new Date(item.effectiveUseBy).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
    : '';

  switch (item.effectiveUseByBasis) {
    case 'after_opening':
      return `Il reste ${quantity}, entamé : à utiliser avant le ${date}.`;
    case 'estimated':
      return `Il reste ${quantity}. Fraîcheur estimée jusqu'au ${date} : vérifiez avant de consommer.`;
    default:
      return `Il reste ${quantity} à consommer avant le ${date}.`;
  }
}
