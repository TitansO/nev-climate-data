<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthenticationController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
    ) {
    }

    #[Route('/api/auth/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'email' => $user->getUserIdentifier(),
            'role' => strtolower(str_replace('ROLE_', '', $user->getRoles()[0])),
        ]);
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $refreshToken = $this->refreshTokenManager->getLastFromUsername($user->getUserIdentifier());
        if (null !== $refreshToken) {
            $this->refreshTokenManager->delete($refreshToken);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
