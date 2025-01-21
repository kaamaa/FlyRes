<?php

namespace App\Security;

use Symfony\Component\Security\Http\Logout\LogoutHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class CustomLogoutHandler implements LogoutHandlerInterface
{
    private $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }

    public function logout(Request $request, Response $response, $token)
    {
        // Invalidate the session
        $request->getSession()->invalidate();

        // Clear the security token
        $this->tokenStorage->setToken(null);

        // Optionally clear other cookies or perform additional cleanup
        $response->headers->clearCookie('REMEMBERME');
    }
}
