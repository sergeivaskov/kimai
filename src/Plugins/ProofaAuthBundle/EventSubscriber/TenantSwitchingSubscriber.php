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

        // If no header, try to extract from JWT payload
        if (!$workspaceId) {
            $token = $this->extractToken($request);
            if ($token) {
                try {
                    $payload = $this->jwtValidator->validate($token);
                    if (isset($payload['workspace_id'])) {
                        $workspaceId = $payload['workspace_id'];
                    }
                } catch (\Exception $e) {
                    // Ignore, authenticator will handle it later in the cycle
                }
            }
        }

        if ($workspaceId) {
            // Sanitize workspace ID to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $workspaceId)) {
                return;
            }

            $schemaName = 'ws_' . strtolower(str_replace('-', '_', $workspaceId));
            $conn = $this->entityManager->getConnection();
            
            try {
                // Check if schema exists before switching
                $result = $conn->executeQuery(
                    'SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?',
                    [$schemaName]
                )->fetchOne();
                
                if ($result) {
                    // Schema exists, switch to it
                    $conn->executeStatement(sprintf('SET search_path TO "%s"', $schemaName));
                } else {
                    // Schema doesn't exist, fallback to public (test environment)
                    $conn->executeStatement('SET search_path TO "public"');
                }
            } catch (\Exception $e) {
                // Fallback to public on any error
                try {
                    $conn->executeStatement('SET search_path TO "public"');
                } catch (\Exception $fallbackException) {
                    // Ignore
                }
            }
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
