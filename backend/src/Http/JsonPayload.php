<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Lecture typée et défensive du corps JSON des requêtes. */
final readonly class JsonPayload
{
    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $content = $request->getContent();

        if ('' === $content) {
            return new self([]);
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Corps de requête JSON invalide.', $exception);
        }

        return new self(is_array($decoded) ? $decoded : []);
    }

    /**
     * Lecture d'un objet imbriqué — une entrée de `batches`, par exemple —
     * avec les mêmes garanties de typage que le corps de la requête.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Le corps brut — pour calculer l'empreinte d'une requête (idempotence),
     * pas pour lire un champ : les accesseurs typés ci-dessous restent le seul
     * point de passage pour ça.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->data;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return $default;
        }

        if (!is_string($value)) {
            throw new BadRequestHttpException(sprintf('Le champ « %s » doit être une chaîne.', $key));
        }

        $value = trim($value);

        return '' === $value ? $default : $value;
    }

    public function requiredString(string $key): string
    {
        $value = $this->string($key);

        if (null === $value) {
            throw new BadRequestHttpException(sprintf('Le champ « %s » est obligatoire.', $key));
        }

        return $value;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->data[$key] ?? null;

        if (null === $value || '' === $value) {
            return $default;
        }

        if (!is_numeric($value)) {
            throw new BadRequestHttpException(sprintf('Le champ « %s » doit être un nombre.', $key));
        }

        return (float) $value;
    }

    public function requiredFloat(string $key): float
    {
        $value = $this->float($key);

        if (null === $value) {
            throw new BadRequestHttpException(sprintf('Le champ « %s » est obligatoire.', $key));
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->float($key);

        return null === $value ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        return null === $value ? $default : filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($entry) => is_string($entry) ? trim($entry) : null, $value),
            static fn (?string $entry) => null !== $entry && '' !== $entry,
        ));
    }

    /**
     * Un objet imbriqué — la recette entière d'un enregistrement.
     *
     * Absent ou mal typé, il vaut le tableau vide : c'est au destinataire de dire
     * ce qui lui manque, avec un message qui parle de recette et non de JSON.
     *
     * @return array<string, mixed>
     */
    public function object(string $key): array
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    public function objectList(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    public function date(string $key): ?\DateTimeImmutable
    {
        $value = $this->string($key);

        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $date) {
            throw new BadRequestHttpException(sprintf('Le champ « %s » doit être une date au format AAAA-MM-JJ.', $key));
        }

        return $date;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T|null
     */
    public function enum(string $key, string $enumClass, ?\BackedEnum $default = null): ?\BackedEnum
    {
        $value = $this->string($key);

        if (null === $value) {
            return $default;
        }

        $case = $enumClass::tryFrom($value);

        if (null === $case) {
            throw new BadRequestHttpException(sprintf('Valeur « %s » invalide pour le champ « %s ».', $value, $key));
        }

        return $case;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T
     */
    public function requiredEnum(string $key, string $enumClass): \BackedEnum
    {
        $case = $this->enum($key, $enumClass);

        if (null === $case) {
            throw new BadRequestHttpException(sprintf('Le champ « %s » est obligatoire.', $key));
        }

        return $case;
    }
}
