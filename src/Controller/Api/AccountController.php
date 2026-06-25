<?php

namespace App\Controller\Api;

use App\Entity\FresAccounts;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /api/me – Identitaet des angemeldeten Nutzers.
 *
 * Dient der PWA als Anmelde-Probe: 200 + Userdaten = eingeloggt,
 * 401 = Login noetig.
 */
class AccountController extends ApiController
{
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof FresAccounts) {
            return $this->json(['error' => 'not_authenticated'], 401);
        }

        $roles = $user->getRoles();

        return $this->json([
            'id'           => $user->getId(),
            'username'     => $user->getUsername(),
            'firstname'    => $user->getFirstname(),
            'lastname'     => $user->getLastname(),
            'email'        => $user->getEmail(),
            'clientid'     => $user->getClientid(),
            'roles'        => $roles,
            'isInstructor' => in_array('ROLE_FI', $roles, true),
            'isAdmin'      => in_array('ROLE_ADMIN', $roles, true),
        ]);
    }
}
