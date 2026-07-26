<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Household;
use App\Entity\Member;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use App\Service\HouseholdAccessKey;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Remplace les comptes par un code de foyer et un prénom.
 *
 * Il n'y a ni e-mail, ni mot de passe : le foyer est une porte dont chaque membre
 * a la clé. Deux garanties sont apportées ici, et elles le sont en un seul endroit :
 *
 * - AUTHENTIFICATION : sans le code du foyer, aucune donnée n'est lisible ni
 *   modifiable. L'identifiant du foyer ne suffit pas — il n'est pas un secret,
 *   il circule dans les réponses de l'API.
 * - ISOLATION : un foyer ne peut jamais lire ni modifier le stock d'un autre,
 *   et un membre ne peut pas être attribué à un foyer auquel il n'appartient pas.
 */
class RequestContext
{
    public const HOUSEHOLD_HEADER = 'X-Household';
    public const MEMBER_HEADER = 'X-Member';
    public const KEY_HEADER = 'X-Household-Key';

    private ?Household $resolved = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly HouseholdRepository $households,
        private readonly MemberRepository $members,
        private readonly HouseholdAccessKey $accessKeys,
    ) {
    }

    public function household(): Household
    {
        // Plusieurs appels par requête (contrôleur, présentation, member()) :
        // on ne revérifie pas le code à chaque fois.
        if (null !== $this->resolved) {
            return $this->resolved;
        }

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

        return $this->resolved = $this->authenticate($household);
    }

    /**
     * Le code du foyer conditionne tout accès.
     *
     * Un foyer sans code — créé avant l'ouverture de l'API sur Internet — est
     * refusé plutôt que laissé passer : une valeur absente ne vaut jamais
     * autorisation. `app:households:issue-keys` lui en délivre un.
     */
    private function authenticate(Household $household): Household
    {
        $key = $this->header(self::KEY_HEADER);

        if (null === $key) {
            throw new UnauthorizedHttpException(
                'Header',
                'Code du foyer manquant. Saisissez-le pour rejoindre ce foyer.',
            );
        }

        if (!$household->hasAccessKey()) {
            throw new UnauthorizedHttpException(
                'Header',
                'Ce foyer n\'a pas encore de code d\'accès. Exécutez « app:households:issue-keys » sur le serveur.',
            );
        }

        if (!$this->accessKeys->matches($key, (string) $household->getAccessKeyHash())) {
            throw new UnauthorizedHttpException('Header', 'Code du foyer incorrect.');
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
