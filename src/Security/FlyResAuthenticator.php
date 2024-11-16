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
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use App\LogonType;

class FlyResAuthenticator extends AbstractAuthenticator
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager; 
    }
    
    /** 
     * Called on every request to decide if this authenticator should be
     * used for the request. Returning `false` will cause this authenticator
     * to be skipped.
     */
    public function supports(Request $request): ?bool
    {
      if ($request->getPathInfo() === '/loginwithcredentials')
      {
        return true;
      }
    }

    public function authenticate(Request $request): Passport
    {
      
      $ary = $request->query->all();
      $str = array_key_first($ary);
      
      $parameters = json_decode($str, true);
      $username = $parameters['username'];
      $password = $parameters['password'];
      $passport = new Passport(new UserBadge($username), new PasswordCredentials($password));
              
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
      return null;
    }
    
    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
      $token = parent::createAuthenticatedToken($passport, $firewallName);
      return $token;
    }
}