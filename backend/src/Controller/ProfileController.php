<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ProfileController extends AbstractApiController
{
    #[Route('/me', methods: ['GET'])]
    public function me(ApiPresenter $presenter): JsonResponse
    {
        return $this->json($presenter->user($this->currentUser()));
    }

    #[Route('/me', methods: ['PATCH'])]
    public function update(
        Request $request,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);
        $user = $this->currentUser();

        if ($payload->has('name')) {
            $user->setName($payload->requiredString('name'));
        }

        if ($payload->has('dietaryExclusions')) {
            $user->setDietaryExclusions($payload->stringList('dietaryExclusions'));
        }

        if ($payload->has('householdName')) {
            $user->getHousehold()->setName($payload->requiredString('householdName'));
        }

        $entityManager->flush();

        return $this->json($presenter->user($user));
    }
}
