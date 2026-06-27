<?php
namespace App\Security;

use App\Repository\FresApiTokenRepository;
use App\Service\ApiTokenService;
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

/**
 * Bearer-Token-Authenticator fuer die FlutterFlow-App.
 *
 * Triggert ausschliesslich, wenn ein "Authorization: Bearer <token>"-Header
 * vorhanden ist. Andernfalls faellt die Anfrage durch zum naechsten
 * Authenticator (Cookie-basierter LoginFormAuthenticator).
 *
 * Bei gueltigem Token werden last_used_at, last_ip und user_agent aktualisiert.
 */
class BearerTokenAuthenticator extends AbstractAuthenticator
{
    public const REQUEST_ATTR = '_auth_bearer';

    public function __construct(
        private readonly FresApiTokenRepository $tokens,
        private readonly ApiTokenService $tokenService,
    ) {}

    public function supports(Request $request): ?bool
    {
        $h = $request->headers->get('Authorization');
        return $h !== null && str_starts_with($h, 'Bearer ') && trim(substr($h, 7)) !== '';
    }

    public function authenticate(Request $request): Passport
    {
        $raw = substr($request->headers->get('Authorization'), 7);
        $hash = $this->tokenService->hash($raw);

        $apiToken = $this->tokens->findByHash($hash);
        if ($apiToken === null) {
            throw new CustomUserMessageAuthenticationException('invalid_token');
        }
        $expires = $apiToken->getExpiresAt();
        if ($expires !== null && $expires < new \DateTime()) {
            throw new CustomUserMessageAuthenticationException('token_expired');
        }

        // Last-used + Audit-Update (synchron, 1 Write pro Request)
        $apiToken->setLastUsedAt(new \DateTime());
        $apiToken->setLastIp($request->getClientIp());
        $apiToken->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));
        $this->tokens->save($apiToken);

        // Markierung fuer ApiController::denyCrossOrigin() - Bearer-Requests
        // brauchen keinen Origin-Check (mobile App sendet keinen Origin-Header).
        $request->attributes->set(self::REQUEST_ATTR, $apiToken->getId());

        $username = $apiToken->getUser()->getUsername();
        return new SelfValidatingPassport(new UserBadge($username));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey() ?: 'invalid_token'], Response::HTTP_UNAUTHORIZED);
    }
}
