<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Household;
use App\Entity\Member;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use App\Repository\StockItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/households')]
class HouseholdController extends AbstractApiController
{
    /** Liste des foyers, pour choisir le sien au premier lancement. */
    #[Route('', methods: ['GET'])]
    public function list(HouseholdRepository $households, ApiPresenter $presenter): JsonResponse
    {
        return $this->json([
            'households' => array_map(
                static fn (Household $household) => $presenter->household($household),
                $households->findAllWithCounts(),
            ),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $household = new Household($payload->requiredString('name'));
        $entityManager->persist($household);

        // Un foyer sans personne n'aurait aucun usage : on crée le premier membre.
        $firstName = $payload->string('firstName');
        if (null !== $firstName) {
            $member = new Member($household, $firstName);
            $entityManager->persist($member);
            // Doctrine ne renseigne pas le côté inverse : sans cela, la réponse
            // renverrait un foyer sans membre et l'application resterait bloquée.
            $household->addMember($member);
        }

        $entityManager->flush();

        return $this->json($presenter->household($household), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id, HouseholdRepository $households, ApiPresenter $presenter): JsonResponse
    {
        return $this->json($presenter->household($this->find($households, $id)));
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        string $id,
        Request $request,
        HouseholdRepository $households,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $this->find($households, $id);
        $payload = JsonPayload::fromRequest($request);

        if ($payload->has('name')) {
            $household->setName($payload->requiredString('name'));
        }

        if ($payload->has('dietaryExclusions')) {
            $household->setDietaryExclusions($payload->stringList('dietaryExclusions'));
        }

        $entityManager->flush();

        return $this->json($presenter->household($household));
    }

    /** Supprime un foyer et tout son stock. Irréversible, d'où la confirmation exigée. */
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        string $id,
        Request $request,
        HouseholdRepository $households,
        StockItemRepository $stockItems,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $this->find($households, $id);
        $items = $stockItems->search($household, ['includeEmpty' => true]);

        // Effacer un stock entier par inadvertance serait irrattrapable.
        if ([] !== $items && !JsonPayload::fromRequest($request)->bool('confirmDeleteStock')) {
            throw new ConflictHttpException(sprintf(
                'Ce foyer contient encore %d produit(s). Confirmez la suppression pour tout effacer.',
                count($items),
            ));
        }

        foreach ($items as $item) {
            $entityManager->remove($item);
        }

        $entityManager->remove($household);
        $entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/members', methods: ['POST'])]
    public function addMember(
        string $id,
        Request $request,
        HouseholdRepository $households,
        MemberRepository $members,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $this->find($households, $id);
        $firstName = JsonPayload::fromRequest($request)->requiredString('firstName');

        if (null !== $members->findOneByFirstName($household, $firstName)) {
            throw new ConflictHttpException(sprintf('« %s » fait déjà partie de ce foyer.', $firstName));
        }

        $member = new Member($household, $firstName);
        $entityManager->persist($member);
        $entityManager->flush();

        return $this->json($presenter->member($member), Response::HTTP_CREATED);
    }

    #[Route('/{id}/members/{memberId}', methods: ['DELETE'])]
    public function removeMember(
        string $id,
        string $memberId,
        HouseholdRepository $households,
        MemberRepository $members,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $this->find($households, $id);

        $member = Uuid::isValid($memberId) ? $members->find(Uuid::fromString($memberId)) : null;
        if (null === $member || !$member->belongsTo($household)) {
            throw new NotFoundHttpException('Membre introuvable.');
        }

        // Les mouvements déjà signés conservent leur trace (createdBy passe à NULL).
        $entityManager->remove($member);
        $entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Le foyer et le membre actuellement sélectionnés par l'application. */
    #[Route('/current/context', methods: ['GET'])]
    public function currentContext(RequestContext $context, ApiPresenter $presenter): JsonResponse
    {
        $member = $context->member();

        return $this->json([
            'household' => $presenter->household($context->household()),
            'member' => null !== $member ? $presenter->member($member) : null,
        ]);
    }

    private function find(HouseholdRepository $households, string $id): Household
    {
        $household = Uuid::isValid($id) ? $households->find(Uuid::fromString($id)) : null;

        if (null === $household) {
            throw new NotFoundHttpException('Foyer introuvable.');
        }

        return $household;
    }
}
