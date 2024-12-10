<?php   

// src/Security/CustomRememberMeHandler.php
namespace App\Security;


use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\RememberMe\PersistentTokenBasedRememberMeServices;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use App\Entity\FresAccounts;

class CustomRememberMeHandler implements RememberMeHandlerInterface
{
    private $rememberMeServices;

    public function __construct(PersistentTokenBasedRememberMeServices $rememberMeServices)
    {
        $this->rememberMeServices = $rememberMeServices;
    }

    public function createRememberMeCookie(UserInterface $user): void
    {
        $this->rememberMeServices->createRememberMeCookie($user);
    }

    public function consumeRememberMeCookie(RememberMeDetails $rememberMeDetails): UserInterface
    {
        return $this->rememberMeServices->consumeRememberMeCookie($rememberMeDetails);
    }

    public function clearRememberMeCookie(): void
    {
        $this->rememberMeServices->clearRememberMeCookie();
    }
}

