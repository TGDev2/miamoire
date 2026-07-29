<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Household;
use App\Entity\Member;
use App\Enum\ExclusionSeverity;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Http\RequestContext;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use App\Repository\StockItemRepository;
use App\Service\HouseholdAccessKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/households')]
class HouseholdController extends AbstractApiController
{
    /**
     * Crée un foyer et délivre son code d'accès.
     *
     * C'est l'unique instant où le code circule en clair : la base n'en garde
     * qu'une empreinte. L'application doit le conserver — et le faire noter.
     */
    #[Route('', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        HouseholdAccessKey $accessKeys,
        ApiPresenter $presenter,
        RateLimiterFactory $householdCreationLimiter,
    ): JsonResponse {
        $this->throttle($householdCreationLimiter, $request);

        $payload = JsonPayload::fromRequest($request);

        $household = new Household($payload->requiredString('name'));
        $code = $accessKeys->generate();
        $household->setAccessKeyHash($accessKeys->hash($code));
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

        return $this->json(
            $presenter->household($household) + ['accessKey' => $code],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Rejoindre un foyer à partir de son code.
     *
     * Remplace l'ancienne liste publique des foyers : sur un serveur accessible
     * depuis Internet, énumérer les foyers reviendrait à publier les portes.
     */
    #[Route('/join', methods: ['POST'])]
    public function join(
        Request $request,
        HouseholdRepository $households,
        HouseholdAccessKey $accessKeys,
        ApiPresenter $presenter,
        RateLimiterFactory $householdJoinLimiter,
    ): JsonResponse {
        $this->throttle($householdJoinLimiter, $request);

        $code = JsonPayload::fromRequest($request)->requiredString('code');

        // Une saisie tronquée ne doit pas coûter une lecture en base.
        if (!$accessKeys->looksValid($code)) {
            throw new NotFoundHttpException('Ce code ne correspond à aucun foyer.');
        }

        $household = $households->findOneByAccessKeyHash($accessKeys->hash($code));

        if (null === $household) {
            throw new NotFoundHttpException('Ce code ne correspond à aucun foyer.');
        }

        return $this->json($presenter->household($household));
    }

    /**
     * Délivre un nouveau code — code perdu, ou téléphone égaré.
     *
     * Exige l'ancien code, via RequestContext : sans quoi n'importe qui pourrait
     * s'emparer d'un foyer dont il connaît l'identifiant. Les autres téléphones
     * du foyer devront saisir le nouveau code.
     */
    #[Route('/{id}/access-key', methods: ['POST'])]
    public function rotateAccessKey(
        string $id,
        RequestContext $context,
        HouseholdAccessKey $accessKeys,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $this->sameAsContext($context, $id);

        $code = $accessKeys->generate();
        $household->setAccessKeyHash($accessKeys->hash($code));
        $entityManager->flush();

        return $this->json($presenter->household($household) + ['accessKey' => $code]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id, RequestContext $context, ApiPresenter $presenter): JsonResponse
    {
        return $this->json($presenter->household($this->sameAsContext($context, $id)));
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        string $id,
        Request $request,
        RequestContext $context,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $this->sameAsContext($context, $id);
        $payload = JsonPayload::fromRequest($request);

        if ($payload->has('name')) {
            $household->setName($payload->requiredString('name'));
        }

        if ($payload->has('dietaryExclusions')) {
            $household->setDietaryExclusions(array_map(
                static fn (array $entry): array => [
                    'label' => JsonPayload::fromArray($entry)->requiredString('label'),
                    'severity' => JsonPayload::fromArray($entry)
                        ->requiredEnum('severity', ExclusionSeverity::class)->value,
                ],
                $payload->objectList('dietaryExclusions'),
            ));
        }

        if ($payload->has('mutedReviewCategories')) {
            $household->setMutedReviewCategories($payload->stringList('mutedReviewCategories'));
        }

        $entityManager->flush();

        return $this->json($presenter->household($household));
    }

    /** Supprime un foyer et tout son stock. Irréversible, d'où la confirmation exigée. */
    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        string $id,
        Request $request,
        RequestContext $context,
        StockItemRepository $stockItems,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $this->sameAsContext($context, $id);
        // Archives comprises : supprimer un foyer efface aussi ce qu'il avait mis de côté.
        $items = $stockItems->search($household, ['includeEmpty' => true, 'archived' => null]);

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
        RequestContext $context,
        MemberRepository $members,
        EntityManagerInterface $entityManager,
        ApiPresenter $presenter,
    ): JsonResponse {
        $household = $this->sameAsContext($context, $id);
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
        RequestContext $context,
        MemberRepository $members,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $this->sameAsContext($context, $id);

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

    /**
     * Le foyer de l'URL doit être celui que le code d'accès a ouvert.
     *
     * `RequestContext::household()` a déjà vérifié le code ; il reste à refuser
     * qu'un foyer authentifié agisse sur un autre par son identifiant d'URL.
     */
    private function sameAsContext(RequestContext $context, string $id): Household
    {
        $household = $context->household();

        if (!Uuid::isValid($id) || !$household->getId()->equals(Uuid::fromString($id))) {
            // On ne distingue pas « inexistant » de « appartient à quelqu'un d'autre ».
            throw new NotFoundHttpException('Foyer introuvable.');
        }

        return $household;
    }

    /**
     * Les deux seuls points d'entrée ouverts sans code : création et adhésion.
     *
     * Le code approche 100 bits d'aléa, il n'est pas devinable ; la limite protège
     * de la création en masse de foyers et du bruit sur un serveur public.
     */
    private function throttle(RateLimiterFactory $limiter, Request $request): void
    {
        if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Trop de tentatives. Réessayez dans quelques minutes.');
        }
    }
}
