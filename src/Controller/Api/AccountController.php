<?php

namespace App\Controller\Api;

use App\Entity\FresAccounts;
use App\Entities\Clients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /api/me – Identitaet des angemeldeten Nutzers.
 *
 * Dient der PWA als Anmelde-Probe: 200 + Userdaten = eingeloggt,
 * 401 = Login noetig.
 */
class AccountController extends ApiController
{
    /**
     * GET /api/clients – Mandantenliste fuer die Login-Auswahl.
     *
     * Bewusst OHNE Anmeldepruefung: wird vor dem Login geladen, damit der
     * Nutzer in der PWA den richtigen Mandanten waehlen kann. Der Login selbst
     * loest den Mandanten dann per Name auf (Clients::GetClientIdByName).
     */
    public function clients(EntityManagerInterface $em): JsonResponse
    {
        $out = [];
        foreach ((Clients::GetAllClientsForListbox($em) ?: []) as $c) {
            $out[] = ['id' => $c['id'], 'name' => $c['name']];
        }

        return $this->json($out);
    }

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
