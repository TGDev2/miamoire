<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vérification d'accessibilité de l'API depuis le téléphone.
 *
 * Seule route qui répond sans en-tête `X-Household` : elle sert précisément à
 * savoir si le serveur est joignable, avant même d'avoir choisi un foyer.
 * En 4G via Tailscale, elle distingue « mauvaise adresse » de « PC éteint ».
 *
 * Elle ne révèle rien : ni foyer, ni stock, ni version des dépendances.
 */
#[Route('/api/health')]
class HealthController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'time' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
    }
}
