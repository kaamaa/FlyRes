<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\LogonType;

class FlyResAuthenticator extends AbstractAuthenticator
{
    // Wird NICHT von der Firewall verwendet (nicht in security.yaml registriert),
    // sondern nur programmatisch: LoginController::apiLogin ruft
    // authenticateUser($user, $this, $request) auf. Daher werden supports() und
    // authenticate() nie aufgerufen; genutzt werden nur onAuthenticationSuccess()
    // und die Token-Erzeugung.

    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /** Nie firewall-seitig genutzt (nur programmatischer Login). */
    public function supports(Request $request): ?bool
    {
      return false;
    }

    public function authenticate(Request $request): Passport
    {
      // Von AbstractAuthenticator gefordert, hier aber nie aufgerufen: der Login
      // laeuft ausschliesslich programmatisch ueber authenticateUser().
      throw new \LogicException('FlyResAuthenticator wird nur programmatisch genutzt (authenticateUser); authenticate() ist nicht vorgesehen.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
      $session = $request->getSession();
      LogonType::defineInFrame($session);
      return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
      return null;
    }

    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
      $token = parent::createAuthenticatedToken($passport, $firewallName);
      return $token;
    }
}