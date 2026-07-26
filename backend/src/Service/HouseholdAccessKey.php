<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Le code d'accès d'un foyer : ce qui remplace les comptes et les mots de passe.
 *
 * L'application est destinée à une famille, pas à des inconnus qui s'inscrivent
 * seuls. Le modèle mental reste celui d'une porte dont chacun a la clé : un code
 * unique par foyer, transmis une fois de la main à la main, conservé par
 * l'application. Aucun e-mail, aucun mot de passe oublié, aucune réinitialisation.
 *
 * Le code est HACHÉ EN SHA-256, pas avec bcrypt. Un hachage lent existe pour
 * protéger des mots de passe humains, courts et devinables. Ici le secret fait
 * près de 100 bits d'aléa : le deviner est hors d'atteinte, et un hachage rapide
 * permet en prime de retrouver un foyer par son code en une lecture indexée.
 */
class HouseholdAccessKey
{
    /**
     * 30 caractères, soit 20 × log2(30) ≈ 98 bits d'entropie par code.
     *
     * De chaque paire confondable à l'oral ou à l'œil, un seul membre est gardé :
     * O sans 0, ni I ni 1, 8 sans B, 5 sans S. Un code se dicte au téléphone ou se
     * recopie depuis un SMS sans jeu de devinettes.
     */
    private const ALPHABET = '23456789ACDEFGHJKLMNPQRTUVWXYZ';

    private const LENGTH = 20;

    private const GROUP_SIZE = 4;

    /** Un code lisible, prêt à être affiché : « K7M2-9QXR-4TVB-8NHD-3JPC ». */
    public function generate(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $this->format($code);
    }

    /**
     * Empreinte stockée en base. Le code en clair n'y figure jamais : une fuite de
     * la base ne donnerait accès à aucun foyer.
     */
    public function hash(string $code): string
    {
        return hash('sha256', $this->normalize($code));
    }

    /** Comparaison en temps constant — un `===` fuiterait la longueur du préfixe correct. */
    public function matches(string $code, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($code));
    }

    /**
     * Tolère ce qu'un humain produit en recopiant : minuscules, espaces, tirets
     * placés ailleurs. « k7m2 9qxr-4tvb8nhd3jpc » vaut « K7M2-9QXR-4TVB-8NHD-3JPC ».
     */
    public function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    }

    /** Le code est-il de la bonne forme ? Évite d'interroger la base pour une saisie tronquée. */
    public function looksValid(string $code): bool
    {
        $normalized = $this->normalize($code);

        return self::LENGTH === strlen($normalized)
            && 1 === preg_match('/^['.self::ALPHABET.']+$/', $normalized);
    }

    private function format(string $code): string
    {
        return implode('-', str_split($code, self::GROUP_SIZE));
    }
}
