<?php

namespace App\Plugins\ProofaAuthBundle\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

use Psr\Log\LoggerInterface;

class JwtAuthenticator extends AbstractAuthenticator
{
    private JwtValidator $jwtValidator;
    private LoggerInterface $logger;

    public function __construct(JwtValidator $jwtValidator, LoggerInterface $logger)
    {
        $this->jwtValidator = $jwtValidator;
        $this->logger = $logger;
    }

    public function supports(Request $request): ?bool
    {
        $token = $this->extractToken($request);
        if (!$token) {
            return false;
        }

        // Only support if it looks like a JWT (3 parts separated by dots)
        // This prevents intercepting native Kimai API tokens
        return substr_count($token, '.') === 2;
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);

        if (null === $token) {
            throw new CustomUserMessageAuthenticationException('No JWT token provided');
        }

        try {
            $payload = $this->jwtValidator->validate($token);
        } catch (ExpiredException $e) {
            throw new CustomUserMessageAuthenticationException('Token expired');
        } catch (SignatureInvalidException $e) {
            throw new CustomUserMessageAuthenticationException('Invalid token signature');
        } catch (BeforeValidException $e) {
            throw new CustomUserMessageAuthenticationException('Token not yet valid');
        } catch (\UnexpectedValueException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage());
        } catch (\Exception $e) {
            throw new CustomUserMessageAuthenticationException('Invalid JWT token: ' . $e->getMessage());
        }

        $userId = $payload['sub'] ?? null;
        if (!$userId) {
            throw new CustomUserMessageAuthenticationException('Invalid JWT payload: missing sub');
        }

        if (isset($payload['workspace_id'])) {
            $request->attributes->set('workspace_id', $payload['workspace_id']);
        }

        // We will pass the payload to the UserProvider via a custom identifier
        // Format: affine_id|json_encoded_payload
        $identifier = $userId . '|' . json_encode($payload);

        return new SelfValidatingPassport(new UserBadge($identifier));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        $this->logger->info('JWT Authentication success', [
            'event' => 'login_success',
            'user_id' => $user ? $user->getUserIdentifier() : 'unknown',
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent')
        ]);
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('JWT Authentication failed', [
            'event' => 'invalid_jwt',
            'reason' => strtr($exception->getMessageKey(), $exception->getMessageData()),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent')
        ]);

        $data = [
            'message' => strtr($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    private function extractToken(Request $request): ?string
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
