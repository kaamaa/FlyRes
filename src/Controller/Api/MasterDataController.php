<?php

namespace App\Controller\Api;

use App\Entities\Airfields;
use App\Entities\FlightPurposes;
use App\Entities\Planes;
use App\Entities\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Stammdaten-Endpoints fuer die PWA-Dropdowns und -Filter.
 * Nutzt die bestehenden Helfer aus App\Entities (keine Logik-Duplikate),
 * gefiltert nach dem Mandanten des angemeldeten Nutzers.
 */
class MasterDataController extends ApiController
{
    /** GET /api/aircraft – aktive Flugzeuge des Mandanten. */
    public function aircraft(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = [];
        foreach (Planes::GetAllPlanesAsObject($em, $user->getClientid()) as $plane) {
            $result[] = [
                'id'       => $plane->getId(),
                'type'     => $plane->getAircraft(),
                'callsign' => $plane->getKennung(),
            ];
        }

        return $this->json($result);
    }

    /** GET /api/instructors – Fluglehrer des Mandanten. */
    public function instructors(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // GetAllFlightinstructorsForListbox liefert [ "Vorname Nachname" => id ]
        $result = [];
        foreach (Users::GetAllFlightinstructorsForListbox($em, $user->getClientid()) as $name => $id) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        return $this->json($result);
    }

    /** GET /api/pilots – Nutzer/Piloten des Mandanten. */
    public function pilots(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // GetAllUsersForListbox liefert [ "Nachname, Vorname" => id ]
        $result = [];
        foreach (Users::GetAllUsersForListbox($em, $user->getClientid()) as $name => $id) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        return $this->json($result);
    }

    /** GET /api/flightpurposes – Flugzwecke (mit Schulungs-Flag). */
    public function flightpurposes(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = [];
        foreach (FlightPurposes::GetFlightPuposeArray($em) as $name => $id) {
            $result[] = [
                'id'         => $id,
                'name'       => $name,
                'isTraining' => FlightPurposes::IsSchulung($id),
            ];
        }

        return $this->json($result);
    }

    /** GET /api/airfields – Flugplaetze. */
    public function airfields(EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = [];
        foreach (Airfields::GetAllAirportsForListbox($em) as $name => $id) {
            $result[] = ['id' => $id, 'name' => $name];
        }

        return $this->json($result);
    }
}
