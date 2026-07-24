<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Household;
use App\Entity\User;
use App\Http\ApiPresenter;
use App\Http\JsonPayload;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/auth')]
class AuthController extends AbstractApiController
{
    #[Route('/register', methods: ['POST'])]
    public function register(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        JWTTokenManagerInterface $jwtManager,
        ValidatorInterface $validator,
        ApiPresenter $presenter,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request);

        $email = mb_strtolower($payload->requiredString('email'));
        $password = $payload->requiredString('password');
        $name = $payload->requiredString('name');
        $householdName = $payload->string('householdName') ?? sprintf('Foyer de %s', $name);

        $violations = $validator->validate($email, [new Assert\Email(message: 'Adresse e-mail invalide.')]);
        if (count($violations) > 0) {
            return $this->json(['error' => 'Adresse e-mail invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($password) < 8) {
            return $this->json(
                ['error' => 'Le mot de passe doit contenir au moins 8 caractères.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (null !== $users->findOneByEmail($email)) {
            return $this->json(['error' => 'Cette adresse e-mail est déjà utilisée.'], Response::HTTP_CONFLICT);
        }

        $household = new Household($householdName);
        $user = new User($email, $name, $household);
        $user->setPassword($hasher->hashPassword($user, $password));

        $entityManager->persist($household);
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json([
            'token' => $jwtManager->create($user),
            'user' => $presenter->user($user),
        ], Response::HTTP_CREATED);
    }

    /**
     * Déclarée pour la documentation et le routage : l'authentification elle-même
     * est prise en charge par le pare-feu `json_login` (voir config/packages/security.yaml).
     */
    #[Route('/login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['error' => 'Identifiants invalides.'], Response::HTTP_UNAUTHORIZED);
    }
}
