/**
 * Ce que « Cuisiner » retient d'une fois sur l'autre.
 *
 * Sept réglages vivaient dans des `useState` d'écran, remis à zéro à chaque
 * ouverture. Un foyer sans four devait redécrire son équipement à chaque
 * génération — or ce n'est pas une propriété de la session, c'est une propriété
 * du logement. Un commentaire d'« Aujourd'hui » affirmait d'ailleurs déjà que
 * « l'utilisateur y retrouve ses réglages habituels » : c'était faux.
 *
 * Les aliments imposés, eux, ne sont PAS retenus : ils désignent des lots
 * précis d'un stock qui bouge, et les ressusciter d'hier serait imposer une
 * contrainte que personne n'a demandée aujourd'hui.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'cook.preferences.v1';

export interface CookPreferences {
  people: number;
  mealType: string | null;
  mealTemperature: string | null;
  maxMinutes: number | null;
  equipment: string[];
  allowExtra: boolean;
}

export function defaultPreferences(householdMembers: number): CookPreferences {
  return {
    // Un foyer sans membre déclaré retombe sur 2 convives, une supposition
    // raisonnable plutôt qu'une vraie donnée manquante.
    people: Math.max(1, householdMembers || 2),
    mealType: null,
    mealTemperature: null,
    maxMinutes: null,
    equipment: [],
    allowExtra: false,
  };
}

/**
 * Relit les réglages, en se méfiant de ce qui est écrit sur le disque.
 *
 * Un référentiel qui change entre deux versions peut avoir retiré un équipement
 * ou un type de repas ; une valeur devenue inconnue partirait alors dans le
 * prompt. Chaque champ est donc revalidé plutôt que recopié, et un stockage
 * illisible retombe sur les défauts sans faire de bruit.
 */
export async function readPreferences(
  householdMembers: number,
  known: { mealTypes: string[]; mealTemperatures: string[]; equipment: string[] },
): Promise<CookPreferences> {
  const fallback = defaultPreferences(householdMembers);

  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (null === raw) return fallback;

    const stored = JSON.parse(raw) as Partial<CookPreferences>;

    const keep = (value: unknown, among: string[]): string | null =>
      'string' === typeof value && among.includes(value) ? value : null;

    return {
      people:
        'number' === typeof stored.people && stored.people >= 1 && stored.people <= 20
          ? stored.people
          : fallback.people,
      mealType: keep(stored.mealType, known.mealTypes),
      mealTemperature: keep(stored.mealTemperature, known.mealTemperatures),
      maxMinutes: 'number' === typeof stored.maxMinutes ? stored.maxMinutes : null,
      equipment: Array.isArray(stored.equipment)
        ? stored.equipment.filter((value): value is string => known.equipment.includes(value as string))
        : [],
      allowExtra: true === stored.allowExtra,
    };
  } catch {
    return fallback;
  }
}

/** Un échec d'écriture ne doit pas empêcher de lancer une génération. */
export async function writePreferences(preferences: CookPreferences): Promise<void> {
  try {
    await AsyncStorage.setItem(KEY, JSON.stringify(preferences));
  } catch {
    // Sans mémoire, l'écran se comporte simplement comme avant.
  }
}
