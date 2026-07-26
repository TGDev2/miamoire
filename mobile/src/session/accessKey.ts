/**
 * Remet un code de foyer dans sa forme canonique : « K7M2-9QXR-4TVB-8NHD-3JPC ».
 *
 * Le serveur accepte le code sous n'importe quelle forme lisible — minuscules,
 * espaces, tirets déplacés. Mais un code conservé tel qu'il a été tapé finirait
 * par être repartagé tel quel, et « mmekylynr9qyv439wuq8 » se recopie mal.
 * On le reformate donc à l'entrée, une fois pour toutes.
 *
 * Miroir exact de `HouseholdAccessKey::normalize()` et `::format()` côté serveur.
 */
export function formatAccessKey(raw: string): string {
  const normalized = raw.toUpperCase().replace(/[^A-Z0-9]/g, '');

  return normalized.match(/.{1,4}/g)?.join('-') ?? normalized;
}
