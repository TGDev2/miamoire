<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Unit;
use App\Http\JsonPayload;

/**
 * Un aliment imposé et, facultativement, ce qu'on veut en écouler.
 *
 * C'est la **demande**, telle qu'elle a été saisie — avant toute confrontation
 * au stock. La cible réellement retenue, elle, est calculée plus tard contre
 * l'inventaire ({@see ResolvedPriority}) : ici on ne sait pas encore ce que pèse
 * un exemplaire, ni combien il en reste.
 *
 * Deux écritures possibles, et **au plus une** :
 *
 * - une quantité dans une unité (« 400 g ») ;
 * - un nombre d'exemplaires (« 1 boîte »), pour le geste qu'on fait en ouvrant
 *   son placard plutôt qu'en pesant.
 *
 * Le constructeur est privé et les fabriques nommées : « les deux à la fois »
 * n'est pas un état à valider plus tard, c'est un état qu'on ne peut pas écrire.
 *
 * Aucune des deux n'est obligatoire — imposer un aliment sans dire combien
 * reste le cas courant, et ne doit rien coûter.
 */
final readonly class PriorityChoice
{
    private function __construct(
        public string $key,
        public ?float $quantity = null,
        public ?Unit $unit = null,
        public ?int $packages = null,
    ) {
    }

    /** L'aliment seul : aucune quantité imposée. */
    public static function of(string $key): self
    {
        return new self($key);
    }

    public static function ofQuantity(string $key, float $quantity, Unit $unit): self
    {
        return new self($key, quantity: $quantity, unit: $unit);
    }

    public static function ofPackages(string $key, int $packages): self
    {
        return new self($key, packages: $packages);
    }

    /**
     * Lit une entrée du corps de requête.
     *
     * La règle tient en une phrase : **un chiffre douteux ne coûte jamais la
     * contrainte elle-même**. Une unité inconnue, une quantité négative, un
     * nombre d'exemplaires à zéro — l'aliment reste imposé, seule la quantité
     * est abandonnée. L'inverse ferait échouer une génération entière pour un
     * champ facultatif par nature.
     *
     * `null` seulement quand il n'y a pas d'aliment du tout : sans clé, il n'y a
     * rien à imposer.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromArray(array $entry): ?self
    {
        $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';

        if ('' === $key) {
            return null;
        }

        $packages = $entry['packages'] ?? null;

        if (is_numeric($packages) && (int) $packages > 0) {
            return self::ofPackages($key, (int) $packages);
        }

        $quantity = $entry['quantity'] ?? null;
        $unit = is_string($entry['unit'] ?? null) ? Unit::tryFrom($entry['unit']) : null;

        if (is_numeric($quantity) && (float) $quantity > 0.0 && null !== $unit) {
            return self::ofQuantity($key, (float) $quantity, $unit);
        }

        return self::of($key);
    }

    /**
     * Les aliments imposés d'un corps de requête, quelle que soit la version de
     * l'application qui appelle.
     *
     * `priorities` porte une quantité facultative par aliment. Mais un APK déjà
     * installé n'envoie que `priorityKeys`, une simple liste de chaînes, et il
     * continuera de le faire aussi longtemps qu'il ne sera pas mis à jour : le
     * backend se déploie seul, l'application non. Ce repli n'est donc pas une
     * politesse — sans lui, imposer un aliment cesserait de fonctionner sur
     * tous les téléphones le jour de la mise en production.
     *
     * @return list<self>
     */
    public static function listFrom(JsonPayload $payload): array
    {
        if ($payload->has('priorities')) {
            return array_values(array_filter(array_map(
                static fn (array $entry): ?self => self::fromArray($entry),
                $payload->objectList('priorities'),
            )));
        }

        return array_map(self::of(...), $payload->stringList('priorityKeys'));
    }

    public function hasAmount(): bool
    {
        return null !== $this->packages || null !== $this->quantity;
    }
}
