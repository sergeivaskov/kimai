<?php

namespace App\Plugins\ProofaAuthBundle\Controller;

use App\Plugins\ProofaAuthBundle\Repository\ProofaUserMappingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly ProofaUserMappingRepository $mappingRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/auth/internal/webhook/logout', name: 'proofa_auth_webhook_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $secret = $request->headers->get('X-Internal-Secret');
        $expectedSecret = $_ENV['INTERNAL_WEBHOOK_SECRET'] ?? 'proofa-internal-secret-key-2024';

        if ($secret !== $expectedSecret) {
            $this->logger->warning('Unauthorized webhook attempt', ['ip' => $request->getClientIp()]);
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $content = json_decode($request->getContent(), true);
        $userId = $content['user_id'] ?? null;
        $workspaceId = $content['workspace_id'] ?? null;

        if (!$userId) {
            return new JsonResponse(['error' => 'Missing user_id'], 400);
        }

        $mapping = $this->mappingRepository->findOneBy(['affineId' => $userId]);

        if (!$mapping) {
            $this->logger->info('Webhook received for unknown user', ['affine_id' => $userId]);
            return new JsonResponse(['status' => 'ignored', 'reason' => 'user not found']);
        }

        $user = $mapping->getKimaiUser();
        
        // Kimai relies on stateless JWT, so there's no active session to destroy in the database.
        // However, we log the event and if there were any user-specific cache, we would clear it here.
        $this->logger->info('User session invalidated via webhook', [
            'affine_id' => $userId,
            'kimai_user_id' => $user->getId(),
            'workspace_id' => $workspaceId
        ]);

        return new JsonResponse(['status' => 'success']);
    }
}
