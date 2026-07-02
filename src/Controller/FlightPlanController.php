<?php

namespace App\Controller;

use App\Tools\NavCalc;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VFR-Flugplanung (Prototyp im modernen Frontend). Ein integrierter Plan: je
 * Wegpunkt eine Eingabezeile, die Streckenwerte (Distanz/Kurs/Steuerkurs/GS/
 * Zeit/Sprit) werden direkt daneben live im Browser berechnet (JS + NavCalc-
 * Portierung). Die Koordinaten zu den ICAO-Codes liefert der JSON-Endpunkt
 * resolve() aus der bestehenden Nav-Datenbank (tools_airports).
 *
 * Der Pfad ist fuer alle angemeldeten Piloten erreichbar; der Menue-Eintrag
 * wird (vorerst) nur Global-Admins gezeigt.
 */
class FlightPlanController extends AbstractController
{
    /** Seite mit dem Planungsformular. */
    public function plan(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');

        $wpRaw = $request->request->all('waypoints');
        $wpInputs = is_array($wpRaw)
            ? array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $wpRaw), static fn ($s) => $s !== ''))
            : [];

        return $this->render('modern/flightplan.html.twig', [
            'wpInputs'  => $wpInputs,
            'tas'       => (int) $request->request->get('tas', 100),
            'windDir'   => (int) $request->request->get('wind_dir', 0),
            'windSpeed' => (int) $request->request->get('wind_speed', 0),
            'fuelRate'  => (float) $request->request->get('fuel_rate', 25),
        ]);
    }

    /**
     * JSON-Aufloesung: ICAO-Codes -> Name + Dezimalkoordinaten. Erwartet
     * POST icaos[]; liefert eine Map { ICAO: {found, name, lat, lon} }.
     */
    public function resolve(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $conn = $em->getConnection();

        $icaos = $request->request->all('icaos');
        if (!is_array($icaos)) {
            $icaos = [];
        }
        $icaos = array_unique(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $icaos), static fn ($s) => $s !== ''));

        $out = [];
        foreach ($icaos as $icao) {
            $row = $conn->fetchAssociative(
                "SELECT ICAO, Airport, sLat, sLong FROM tools_airports WHERE ICAO = ? "
                . "ORDER BY CASE WHEN Type = 'A' THEN 0 ELSE 1 END LIMIT 1",
                [$icao]
            );
            if ($row) {
                $lat = NavCalc::parseCoordinate((string) $row['sLat']);
                $lon = NavCalc::parseCoordinate((string) $row['sLong']);
                $out[$icao] = ($lat !== null && $lon !== null)
                    ? ['found' => true, 'name' => $row['Airport'], 'lat' => $lat, 'lon' => $lon]
                    : ['found' => false];
            } else {
                $out[$icao] = ['found' => false];
            }
        }

        return new JsonResponse($out);
    }
}
