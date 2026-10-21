<?php

declare(strict_types=1);

namespace App\Service\Ai\Generator;

/**
 * Appel au modèle de langage, isolé derrière une interface.
 *
 * Toute la logique métier — inventaire structuré, consignes, validation de la
 * proposition de consommation — vit en dehors : changer de fournisseur ne doit
 * jamais changer le comportement de l'application.
 */
interface RecipeGeneratorInterface
{
    /** Le fournisseur dispose-t-il de sa clé d'API ? */
    public function isConfigured(): bool;

    /** Nom affiché dans les messages d'erreur adressés à l'utilisateur. */
    public function label(): string;

    /** Variable d'environnement à renseigner quand la configuration manque. */
    public function configurationHint(): string;

    /**
     * @param array<string, mixed>                          $schema Schéma JSON imposé à la réponse
     * @param list<array{mediaType: string, data: string}> $images photos jointes au message,
     *                                                              en base64 — un ticket de
     *                                                              caisse à lire, par exemple
     *
     * @return string Le JSON brut renvoyé par le modèle
     */
    public function generate(string $systemPrompt, string $userPayload, array $schema, array $images = []): string;
}
