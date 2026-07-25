<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\Member;
use App\Http\RequestContext;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sans authentification, c'est ce service qui porte toute l'isolation entre foyers.
 * Chaque cas ci-dessous est une porte que l'on ne veut jamais voir s'ouvrir.
 */
class RequestContextTest extends TestCase
{
    public function testResolvesTheSelectedHousehold(): void
    {
        $household = new Household('Maison');

        $context = $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
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
        ])->household();
    }

    /** Le cas critique : un membre d'un autre foyer ne doit jamais signer ici. */
    public function testMemberFromAnotherHouseholdIsIgnored(): void
    {
        $mine = new Household('Maison');
        $other = new Household('Chez les voisins');
        $intruder = new Member($other, 'Intrus');

        $context = $this->context($mine, $intruder, [
            RequestContext::HOUSEHOLD_HEADER => (string) $mine->getId(),
            RequestContext::MEMBER_HEADER => (string) $intruder->getId(),
        ]);

        self::assertNull($context->member(), 'Un membre extérieur au foyer doit être écarté.');
    }

    public function testWritesRequireAnIdentifiedMember(): void
    {
        $household = new Household('Maison');

        $context = $this->context($household, null, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
        ]);

        $this->expectException(BadRequestHttpException::class);
        $context->requireMember();
    }

    public function testMemberOfTheHouseholdIsAccepted(): void
    {
        $household = new Household('Maison');
        $member = new Member($household, 'Theo');

        $context = $this->context($household, $member, [
            RequestContext::HOUSEHOLD_HEADER => (string) $household->getId(),
            RequestContext::MEMBER_HEADER => (string) $member->getId(),
        ]);

        self::assertSame('Theo', $context->requireMember()->getFirstName());
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

        return new RequestContext($stack, $households, $members);
    }
}
