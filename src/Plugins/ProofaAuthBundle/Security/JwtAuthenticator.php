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

class JwtAuthenticator extends AbstractAuthenticator
{
    private JwtValidator $jwtValidator;

    public function __construct(JwtValidator $jwtValidator)
    {
        $this->jwtValidator = $jwtValidator;
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization') || $request->cookies->has('access_token');
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
            throw new CustomUserMessageAuthenticationException('Invalid JWT token');
        }

        $userId = $payload['sub'] ?? null;
        if (!$userId) {
            throw new CustomUserMessageAuthenticationException('Invalid JWT payload: missing sub');
        }

        // We will pass the payload to the UserProvider via a custom identifier
        // Format: affine_id|json_encoded_payload
        $identifier = $userId . '|' . json_encode($payload);

        return new SelfValidatingPassport(new UserBadge($identifier));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
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
