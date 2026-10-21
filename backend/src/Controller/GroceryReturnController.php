<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Service\GroceryReturn\GroceryReturnResolver;
use App\Service\GroceryReturn\GroceryReturnService;
use App\Service\GroceryReturn\ResolvedGroceryLine;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The grocery return: the basket of a shopping trip, filled in by the server
 * and written as one operation.
 *
 * The basket itself stays on the phone, like the preview of an imported
 * recipe: `resolve` is a stateless preview that writes nothing, `confirm`
 * is the single write. Nothing in between needs a server-side object.
 */
#[Route('/api/grocery-returns')]
class GroceryReturnController extends AbstractApiController
{
    /**
     * Fills every empty field of the lines from the household's habits, the
     * catalogue and the lexicons, and names the one question a line may still
     * ask. Sent with one line after an edit, or with the whole basket.
     */
    #[Route('/resolve', methods: ['POST'])]
    public function resolve(
        Request $request,
        RequestContext $context,
        GroceryReturnResolver $resolver,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $lines = $resolver->resolve($context->household(), $payload->objectList('lines'));

        return $this->json([
            'lines' => array_map(
                static fn (ResolvedGroceryLine $line): array => $presenter->groceryReturnLine($line),
                $lines,
            ),
        ]);
    }

    /** Every lot of the return in one idempotent operation; see GroceryReturnService. */
    #[Route('/confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        RequestContext $context,
        GroceryReturnService $service,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $response = $service->confirm(
            $context->household(),
            $context->requireMember(),
            $payload->requiredString('operationKey'),
            $this->requestHash($payload),
            $payload->objectList('lines'),
        );

        return $this->json($response, Response::HTTP_CREATED);
    }

    /** The request's fingerprint, minus the transport details — as the stock routes compute it. */
    private function requestHash(JsonPayload $payload): string
    {
        $data = $payload->raw();
        unset($data['operationKey']);

        return hash('sha256', json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
