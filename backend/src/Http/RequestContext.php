<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Household;
use App\Entity\Member;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Remplace l'authentification par une simple désignation du foyer et du membre.
 *
 * L'application est destinée à une seule famille sur son réseau local : il n'y a
 * ni compte, ni mot de passe. En contrepartie, la seule garantie apportée ici est
 * l'ISOLATION : un foyer ne peut jamais lire ni modifier le stock d'un autre,
 * et un membre ne peut pas être attribué à un foyer auquel il n'appartient pas.
 */
class RequestContext
{
    public const HOUSEHOLD_HEADER = 'X-Household';
    public const MEMBER_HEADER = 'X-Member';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly HouseholdRepository $households,
        private readonly MemberRepository $members,
    ) {
    }

    public function household(): Household
    {
        $id = $this->header(self::HOUSEHOLD_HEADER);

        if (null === $id) {
            throw new BadRequestHttpException('Aucun foyer sélectionné.');
        }

        if (!Uuid::isValid($id)) {
            throw new BadRequestHttpException('Identifiant de foyer invalide.');
        }

        $household = $this->households->find(Uuid::fromString($id));

        if (null === $household) {
            throw new NotFoundHttpException('Ce foyer n\'existe plus. Choisissez-en un autre.');
        }

        return $household;
    }

    /** Le membre est facultatif en lecture : consulter le stock n'exige pas de se nommer. */
    public function member(): ?Member
    {
        $id = $this->header(self::MEMBER_HEADER);

        if (null === $id || !Uuid::isValid($id)) {
            return null;
        }

        $member = $this->members->find(Uuid::fromString($id));

        // Un membre d'un autre foyer ne doit jamais signer un mouvement ici.
        return null !== $member && $member->belongsTo($this->household()) ? $member : null;
    }

    /** Toute écriture doit être attribuable à quelqu'un. */
    public function requireMember(): Member
    {
        $member = $this->member();

        if (null === $member) {
            throw new BadRequestHttpException(
                'Indiquez qui effectue cette action : sélectionnez votre prénom dans le profil.',
            );
        }

        return $member;
    }

    private function header(string $name): ?string
    {
        $value = $this->requestStack->getCurrentRequest()?->headers->get($name);

        return null !== $value && '' !== trim($value) ? trim($value) : null;
    }
}
