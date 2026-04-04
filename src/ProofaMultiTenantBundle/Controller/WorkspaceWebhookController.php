<?php

namespace App\ProofaMultiTenantBundle\Controller;

use App\ProofaMultiTenantBundle\Exception\InvalidWorkspaceIdException;
use App\ProofaMultiTenantBundle\Exception\TenantProvisioningFailedException;
use App\ProofaMultiTenantBundle\Exception\TenantSchemaAlreadyExistsException;
use App\ProofaMultiTenantBundle\Service\TenantProvisioningService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WorkspaceWebhookController extends AbstractController
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/api/webhooks/workspace-created', name: 'api_webhooks_workspace_created', methods: ['POST'])]
    public function workspaceCreated(Request $request): JsonResponse
    {
        $secret = $_ENV['PROOFA_WEBHOOK_SECRET'] ?? null;
        if (!$secret) {
            $this->logger->error('PROOFA_WEBHOOK_SECRET is not configured');
            return new JsonResponse(['error' => 'internal_server_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $signatureHeader = $request->headers->get('X-Webhook-Signature');
        if (!$signatureHeader || !str_starts_with($signatureHeader, 'sha256=')) {
            return new JsonResponse(['error' => 'missing_or_invalid_signature'], Response::HTTP_FORBIDDEN);
        }

        $signature = substr($signatureHeader, 7);
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->warning('invalid_webhook_signature', ['workspace_path' => $request->getPathInfo()]);
            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($payload, true);

        if (!isset($data['workspace_id'])) {
            return new JsonResponse(['error' => 'missing_workspace_id'], Response::HTTP_BAD_REQUEST);
        }

        $workspaceId = $data['workspace_id'];

        try {
            $this->provisioningService->createTenantSchema($workspaceId);

            return new JsonResponse([
                'status' => 'success',
                'message' => sprintf('Schema for workspace %s provisioned successfully.', $workspaceId),
            ]);

        } catch (InvalidWorkspaceIdException $e) {
            return new JsonResponse($e->toArray(), Response::HTTP_FORBIDDEN);
        } catch (TenantSchemaAlreadyExistsException $e) {
            return new JsonResponse($e->toArray(), Response::HTTP_CONFLICT);
        } catch (TenantProvisioningFailedException $e) {
            return new JsonResponse($e->toArray(), Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('unexpected_webhook_error', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse([
                'error' => 'internal_server_error',
                'message' => 'An unexpected error occurred during provisioning.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/webhooks/workspace-deleted', name: 'api_webhooks_workspace_deleted', methods: ['POST'])]
    public function workspaceDeleted(Request $request): JsonResponse
    {
        $secret = $_ENV['PROOFA_WEBHOOK_SECRET'] ?? null;
        if (!$secret) {
            $this->logger->error('PROOFA_WEBHOOK_SECRET is not configured');
            return new JsonResponse(['error' => 'internal_server_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $signatureHeader = $request->headers->get('X-Webhook-Signature');
        if (!$signatureHeader || !str_starts_with($signatureHeader, 'sha256=')) {
            return new JsonResponse(['error' => 'missing_or_invalid_signature'], Response::HTTP_FORBIDDEN);
        }

        $signature = substr($signatureHeader, 7);
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->warning('invalid_webhook_signature', ['workspace_path' => $request->getPathInfo()]);
            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($payload, true);

        if (!isset($data['workspace_id'])) {
            return new JsonResponse(['error' => 'missing_workspace_id'], Response::HTTP_BAD_REQUEST);
        }

        $workspaceId = $data['workspace_id'];

        try {
            $this->provisioningService->handleWorkspaceDeletion($workspaceId);

            return new JsonResponse([
                'status' => 'success',
                'message' => sprintf('Schema for workspace %s archived successfully.', $workspaceId),
            ]);

        } catch (InvalidWorkspaceIdException $e) {
            return new JsonResponse($e->toArray(), Response::HTTP_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'conflict',
                'message' => $e->getMessage()
            ], Response::HTTP_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('unexpected_webhook_error', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse([
                'error' => 'internal_server_error',
                'message' => 'An unexpected error occurred during deletion.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
