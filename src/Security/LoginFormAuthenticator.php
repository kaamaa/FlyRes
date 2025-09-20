<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Entities\Clients;
use App\Entity\FresAccounts;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use App\LogonType;

class LoginFormAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface 
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager) 
    {}
    
    /** 
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning `false` will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request): ?bool
    {
      // Passe route + method an dein Formular an:
      $value = $request->attributes->get('_route') === 'app_login'
            && $request->isMethod('POST');
      return $value;
    }

    public function authenticate(Request $request): Passport
    {
      $username = $request->request->get('_username');
      $password = $request->request->get('_password');
      $client = $request->request->get('client');
      $clientid = Clients::GetClientIdByName ($this->entityManager, $client);
      $this->entityManager->getRepository(FresAccounts::class)->setClient($clientid);
      
      $ub = new UserBadge($username, function($username) {
        return $this->entityManager->getRepository(FresAccounts::class)->loadUserByIdentifier($username);
      });

      $passport =  new Passport($ub, new PasswordCredentials($password), 
      [
        new RememberMeBadge()
      ]);  
              
      return $passport;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
      $session = $request->getSession();
      LogonType::defineStandalone($session);
      // Nach Login wohin? (früher: default_target_path bei form_login)
      // Erst Ziel-URL aus der Session (falls geschützte Seite vorher aufgerufen wurde)
      $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');

      if ($targetPath) {
          return new RedirectResponse($targetPath);
      }

      // Projektspezifische Session-Initialisierung
      LogonType::defineStandalone($request->getSession());

      // Fallback-Ziel nach dem Login
      return new RedirectResponse($this->urlGenerator->generate('_weeksview'));
     
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {

      return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
    
    public function start(Request $request, AuthenticationException $authException = null) : Response
    {
      // Wird aufgerufen, wenn eine geschützte Seite ohne Login aufgerufen wird
      return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
    
    public function supportsRememberMe()
    {
      return true;
    }
    
    public function createAuthenticatedToken(Passport $passport, string $firewallName): TokenInterface
   {
      $token = parent::createAuthenticatedToken($passport, $firewallName);
      return $token;
    }
}