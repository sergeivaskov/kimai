<?php

namespace App\Plugins\ProofaAuthBundle\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use App\Plugins\ProofaAuthBundle\Security\JwtValidator;

class TenantSwitchingSubscriber implements EventSubscriberInterface
{
    private EntityManagerInterface $entityManager;
    private JwtValidator $jwtValidator;

    public function __construct(EntityManagerInterface $entityManager, JwtValidator $jwtValidator)
    {
        $this->entityManager = $entityManager;
        $this->jwtValidator = $jwtValidator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // High priority to run before the authenticator
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            // Low priority to run at the end
            KernelEvents::TERMINATE => ['onKernelTerminate', -256],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $workspaceId = $request->headers->get('X-Workspace-Id');

        // If no header, try to extract from JWT
        if (!$workspaceId) {
            $token = $this->extractToken($request);
            if ($token) {
                try {
                    $payload = $this->jwtValidator->validate($token);
                    // Extract workspace id if present in payload
                    // In AFFiNE, JWT might not have a single workspaceId if it's global,
                    // but we can check if it was passed in some other way or if the client
                    // is required to send X-Workspace-Id.
                    // For safety, we rely on X-Workspace-Id header for tenant routing.
                } catch (\Exception $e) {
                    // Ignore, authenticator will handle it
                }
            }
        }

        if ($workspaceId) {
            // Sanitize workspace ID to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $workspaceId)) {
                return;
            }

            $schemaName = 'ws_' . strtolower($workspaceId);
            $conn = $this->entityManager->getConnection();
            
            // Switch schema
            $conn->executeStatement(sprintf('SET search_path TO "%s"', $schemaName));
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        // Reset schema to public to protect connection pool (PgBouncer)
        $conn = $this->entityManager->getConnection();
        if ($conn->isConnected()) {
            try {
                $conn->executeStatement('SET search_path TO "public"');
            } catch (\Exception $e) {
                // Ignore if connection is already closed
            }
        }
    }

    private function extractToken($request): ?string
    {
        if ($request->headers->has('Authorization')) {
            $header = $request->headers->get('Authorization');
            if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
                return $matches[1];
            }
        }

        if ($request->cookies->has('access_token')) {
            return $request->cookies->get('access_token');
        }

        return null;
    }
}
