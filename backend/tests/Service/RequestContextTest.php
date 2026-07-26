<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\Member;
use App\Http\RequestContext;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use App\Service\HouseholdAccessKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Ce service porte à lui seul l'authentification et l'isolation entre foyers.
 * Chaque cas ci-dessous est une porte que l'on ne veut jamais voir s'ouvrir.
 */
class RequestContextTest extends TestCase
{
    private const CODE = 'K7M2-9QXR-4TVB-8NHD-3JPC';

    private HouseholdAccessKey $accessKeys;

    protected function setUp(): void
    {
        $this->accessKeys = new HouseholdAccessKey();
    }

    public function testResolvesTheSelectedHousehold(): void
    {
        $household = $this->lockedHousehold('Maison');

        $context = $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::KEY_HEADER => self::CODE,
        ]);

        self::assertTrue($context->household()->getId()->equals($household->getId()));
    }

    /** Le cas qui a motivé l'authentification : l'identifiant seul ne doit rien ouvrir. */
    public function testHouseholdIdentifierAloneOpensNothing(): void
    {
        $household = $this->lockedHousehold('Maison');

        $this->expectException(UnauthorizedHttpException::class);

        $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
        ])->household();
    }

    public function testWrongAccessKeyIsRejected(): void
    {
        $household = $this->lockedHousehold('Maison');

        $this->expectException(UnauthorizedHttpException::class);

        $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::KEY_HEADER => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ',
        ])->household();
    }

    /** Un foyer antérieur aux codes est refusé, jamais laissé passer par défaut. */
    public function testHouseholdWithoutAccessKeyIsRejected(): void
    {
        $household = new Household('Foyer hérité');

        $this->expectException(UnauthorizedHttpException::class);

        $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::KEY_HEADER => self::CODE,
        ])->household();
    }

    /** Le code d'un foyer ne doit jamais ouvrir celui d'un autre. */
    public function testAccessKeyOfAnotherHouseholdIsRejected(): void
    {
        $neighbour = new Household('Chez les voisins');
        $neighbour->setAccessKeyHash($this->accessKeys->hash('WXYZ-WXYZ-WXYZ-WXYZ-WXYZ'));

        $this->expectException(UnauthorizedHttpException::class);

        $this->context($neighbour, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $neighbour->getId(),
            RequestContext::KEY_HEADER => self::CODE,
        ])->household();
    }

    /** Espaces, minuscules et tirets déplacés : ce qu'un humain produit en recopiant. */
    public function testAccessKeyIsToleratedInAnyReadableForm(): void
    {
        $household = $this->lockedHousehold('Maison');

        $context = $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::KEY_HEADER => ' k7m2 9qxr-4tvb8nhd 3jpc ',
        ]);

        self::assertTrue($context->household()->getId()->equals($household->getId()));
    }

    public function testMissingHouseholdIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->context(null, null, [])->household();
    }

    public function testMalformedHouseholdIdentifierIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->context(null, null, [RequestContext::HOUSEHOLD_HEADER => 'pas-un-uuid'])->household();
    }

    public function testDeletedHouseholdGivesAClearError(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->context(null, null, [
            RequestContext::HOUSEHOLD_HEADER => '0195f4c0-0000-7000-8000-000000000000',
            RequestContext::KEY_HEADER => self::CODE,
        ])->household();
    }

    /** Le cas critique : un membre d'un autre foyer ne doit jamais signer ici. */
    public function testMemberFromAnotherHouseholdIsIgnored(): void
    {
        $mine = $this->lockedHousehold('Maison');
        $other = new Household('Chez les voisins');
        $intruder = new Member($other, 'Intrus');

        $context = $this->context($mine, $intruder, [
            RequestContext::HOUSEHOLD_HEADER => (string) $mine->getId(),
            RequestContext::KEY_HEADER => self::CODE,
            RequestContext::MEMBER_HEADER => (string) $intruder->getId(),
        ]);

        self::assertNull($context->member(), 'Un membre extérieur au foyer doit être écarté.');
    }

    public function testWritesRequireAnIdentifiedMember(): void
    {
        $household = $this->lockedHousehold('Maison');

        $context = $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::KEY_HEADER => self::CODE,
        ]);

        $this->expectException(BadRequestHttpException::class);
        $context->requireMember();
    }

    public function testMemberOfTheHouseholdIsAccepted(): void
    {
        $household = $this->lockedHousehold('Maison');
        $member = new Member($household, 'Theo');

        $context = $this->context($household, $member, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::KEY_HEADER => self::CODE,
            RequestContext::MEMBER_HEADER => (string) $member->getId(),
        ]);

        self::assertSame('Theo', $context->requireMember()->getFirstName());
    }

    private function lockedHousehold(string $name): Household
    {
        $household = new Household($name);
        $household->setAccessKeyHash($this->accessKeys->hash(self::CODE));

        return $household;
    }

    /** @param array<string, string> $headers */
    private function context(?Household $household, ?Member $member, array $headers): RequestContext
    {
        $request = new Request();
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $stack = new RequestStack();
        $stack->push($request);

        $households = $this->createMock(HouseholdRepository::class);
        $households->method('find')->willReturn($household);

        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturn($member);

        return new RequestContext($stack, $households, $members, $this->accessKeys);
    }
}
