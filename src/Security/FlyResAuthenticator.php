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
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
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
      $username = $request->request->get('_username');
      $password = $request->request->get('_password');
      $client = $request->request->get('client');
      $clientid = Clients::GetClientIdByName($this->entityManager, $client);
      $this->entityManager->getRepository(FresAccounts::class)->setClient($clientid);
      
      $ub = new UserBadge($username);
              
      $passport =  new Passport($ub, new PasswordCredentials($password), 
      [
        //new CsrfTokenBadge('authenticate', $request->get('_csrf_token')),      
        new RememberMeBadge()
      ]);  
      return $passport;
      
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
    
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
      return false;
    }
    
    public function getCredentials(Request $request)
    {
      return null;
    }
    
    public function checkCredentials($credentials, UserInterface $user)
    {
      $password = $credentials['password'];
      if (md5($password) === $user->getPassword()) return true;
        else return false;
    }
    
    public function start(Request $request, AuthenticationException $authException = null)
    {
      return null;
    }
    
    public function supportsRememberMe()
    {
      return true;
    }
    
    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
      $token = parent::createAuthenticatedToken($passport, $firewallName);
      return $token;
    }
}