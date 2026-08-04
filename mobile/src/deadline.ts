import type { SiblingLot, StockItem } from './api/types';

/**
 * L'échéance d'un lot telle que l'interface l'imprime — les trois seules
 * données dont le cachet a besoin.
 *
 * C'est `effectiveUseBy` qui fait foi, pas la date imprimée : un pot ouvert
 * dont l'étiquette dit « mars » se termine dans trois jours, et c'est cette
 * échéance-là qui pilote déjà le stock, Cuisiner et les notifications.
 */
export interface Deadline {
  days: number | null;
  date: string | null;
  estimated: boolean;
}

export function deadlineOf(item: StockItem): Deadline {
  return {
    days: item.daysUntilEffectiveUseBy ?? item.daysUntilExpiration,
    date: item.effectiveUseBy ?? item.expirationDate,
    estimated: item.expirationIsEstimated,
  };
}

/** Un autre lot du même aliment n'expose que sa date imprimée. */
export function deadlineOfSibling(lot: SiblingLot): Deadline {
  return {
    days: lot.daysUntilExpiration,
    date: lot.expirationDate,
    estimated: lot.expirationIsEstimated,
  };
}
