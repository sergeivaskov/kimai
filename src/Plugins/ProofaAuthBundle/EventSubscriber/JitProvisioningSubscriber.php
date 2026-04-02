<?php

namespace App\Plugins\ProofaAuthBundle\EventSubscriber;

use App\Plugins\ProofaAuthBundle\Security\JwtValidator;
use App\Plugins\ProofaAuthBundle\Service\UserProvisioningService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;

class JitProvisioningSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JwtValidator $jwtValidator,
        private UserProvisioningService $provisioningService,
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 10: runs after TenantSwitchingSubscriber (256) but before Authenticator (8)
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $token = $this->extractToken($request);

        if (!$token) {
            return;
        }

        try {
            $payload = $this->jwtValidator->validate($token);
            $this->provisioningService->provisionUser($payload);
        } catch (\Exception $e) {
            // Invalid token or provisioning failed. 
            // We don't throw here; we let the Authenticator handle auth failures.
            $this->logger->debug('JIT Provisioning skipped or failed', [
                'event' => 'jit_provisioning_failed',
                'exception' => $e->getMessage(),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent')
            ]);
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
