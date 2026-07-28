<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\StockException;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use App\Service\Health\DatabaseHealthChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vérification d'accessibilité de l'API depuis le téléphone.
 *
 * Seule route qui répond sans en-tête `X-Household` : elle sert précisément à
 * savoir si le serveur est joignable, avant même d'avoir choisi un foyer.
 * En 4G via Tailscale, elle distingue « mauvaise adresse » de « PC éteint ».
 *
 * Elle ne révèle rien : ni foyer, ni stock, ni version des dépendances. Les
 * variables sensibles n'apparaissent que par leur présence, jamais par leur
 * valeur (leçon nº 10, `tasks/lessons.md` : un déploiement dégradé ne doit pas
 * répondre `200` comme s'il allait bien). La base compte comme une panne réelle
 * — `503` — parce que rien d'autre ne fonctionne sans elle ; l'absence de clé du
 * générateur de recettes compte comme dégradée mais reste `200`, parce qu'elle
 * ne prive que l'onglet Cuisiner, jamais le reste de l'application.
 *
 * Ce qu'elle ne couvre toujours pas : la validité réelle d'une clé présente
 * (une clé révoquée reste « configurée »), et les autres services tiers (Open
 * Food Facts, Firebase). Voir le README, section Déploiement.
 */
#[Route('/api/health')]
class HealthController extends AbstractApiController
{
    public function __construct(
        private readonly DatabaseHealthChecker $databaseHealthChecker,
        private readonly RecipeGeneratorFactory $recipeGenerators,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $databaseReachable = $this->databaseHealthChecker->isReachable();
        [$recipeProvider, $recipeProviderConfigured] = $this->recipeProviderStatus();

        $status = match (true) {
            !$databaseReachable => 'error',
            !$recipeProviderConfigured => 'degraded',
            default => 'ok',
        };

        return $this->json([
            'status' => $status,
            'time' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'database' => $databaseReachable,
            'recipeProvider' => $recipeProvider,
            'recipeProviderConfigured' => $recipeProviderConfigured,
        ], $databaseReachable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function recipeProviderStatus(): array
    {
        try {
            $generator = $this->recipeGenerators->selected();

            return [$generator->label(), $generator->isConfigured()];
        } catch (StockException) {
            // RECIPE_PROVIDER désigne un fournisseur inconnu : la sonde le
            // signale comme non configuré plutôt que de planter elle-même.
            return [null, false];
        }
    }
}
