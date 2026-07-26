<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Service\Ai\RecipeConfirmationService;
use App\Service\Ai\RecipeRequest;
use App\Service\Ai\RecipeSuggestionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/recipes')]
class RecipeController extends AbstractApiController
{
    /** Génère des propositions. Aucune écriture en stock à cette étape. */
    #[Route('/suggest', methods: ['POST'])]
    public function suggest(
        Request $request,
        RequestContext $context,
        RecipeSuggestionService $service,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $household = $context->household();

        // Les exclusions du foyer s'appliquent par défaut, la requête peut les compléter.
        $exclusions = array_values(array_unique(array_merge(
            $household->getDietaryExclusions(),
            $payload->stringList('exclusions'),
        )));

        $result = $service->suggest($household, new RecipeRequest(
            people: max(1, min(20, $payload->int('people', 2) ?? 2)),
            mealType: $payload->string('mealType'),
            maxMinutes: $payload->int('maxMinutes'),
            equipment: $payload->stringList('equipment'),
            exclusions: $exclusions,
            allowExtraIngredients: $payload->bool('allowExtraIngredients'),
        ));

        return $this->json($result);
    }

    /**
     * Applique la déduction après confirmation explicite de l'utilisateur.
     *
     * `operationKey` est obligatoire : c'est elle qui rend le geste rejouable sans
     * effet supplémentaire. Une réponse réseau perdue puis une nouvelle tentative
     * portent la même clé, et ne déduisent donc qu'une fois.
     */
    #[Route('/confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        RequestContext $context,
        RecipeConfirmationService $service,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $result = $service->confirm(
            $context->household(),
            $context->requireMember(),
            $payload->objectList('stockConsumptionProposal'),
            $payload->string('recipeName'),
            $payload->requiredString('operationKey'),
        );

        return $this->json($result);
    }
}
