<?php   

// src/Security/CustomRememberMeService.php
namespace App\Security;

use Symfony\Component\Security\Core\User\UserProviderInterface; 
use Symfony\Component\Security\Http\RememberMe\TokenBasedRememberMeServices;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\FresAccounts;

class CustomRememberMeService extends TokenBasedRememberMeServices
{
    private $entityManager;

    public function __construct(
        array $userProviders,
        string $secret,
        string $providerKey,
        array $options = [],
        EntityManagerInterface $entityManager
    ) 
    {
        parent::__construct($userProviders, $secret, $providerKey, $options);
        $this->entityManager = $entityManager;
    }
    
    protected function onLoginSuccess(Request $request, Response $response, TokenInterface $token)
    {
        $user = $token->getUser();
        $extraData = 'some_extra_data'; // Füge hier deine zusätzlichen Daten hinzu

        $cookieValue = $this->encodeCookie([
            $user->getUsername(),
            time(),
            $extraData, // Füge hier die zusätzlichen Daten hinzu
            $this->generateCookieValue($user)
        ]);

        $this->setCookie($cookieValue, $lifetime, $request, $response);
    }

    protected function processAutoLoginCookie(array $cookieParts, Request $request)
    {
        if (count($cookieParts) !== 4) {
            throw new AuthenticationException('Invalid remember-me cookie.');
        }

        list($username, $time, $extraData, $hash) = $cookieParts;

        // Verifiziere das Cookie und extrahiere zusätzliche Daten
        if (!$this->isValidCookie($hash, $username, $time, $extraData)) {
            throw new AuthenticationException('Invalid remember-me cookie.');
        }

        return $this->userProvider->loadUserByUsername($username);
    }

    private function isValidCookie($hash, $username, $time, $extraData)
    {
        // Füge hier die Logik zur Verifizierung des Cookies hinzu
        return true; // Beispielhaft, implementiere hier deine eigene Logik
    }
}
