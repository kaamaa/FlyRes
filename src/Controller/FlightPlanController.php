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
        // Oeffentlich zugaenglich (siehe security.yaml) – kein Login noetig.

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
            'variation' => (int) $request->request->get('variation', 0),
            'region'    => (string) $request->request->get('region', 'de'),
            'types'     => (string) $request->request->get('types', 'AW'),
            'addStart'  => (int) $request->request->get('add_start', 0),
            'addLand'   => (int) $request->request->get('add_land', 0),
            'addAlt'    => (int) $request->request->get('add_alt', 0),
        ]);
    }

    /**
     * JSON-Aufloesung von Wegpunkt-Eingaben (ICAO oder Klarname). Erwartet POST
     * q[] sowie region (de|eu|all) und types (A|W|AW). Liefert je Eingabe eine
     * Liste moeglicher Treffer (Disambiguierung):
     *   { results: [ { candidates: [ {icao,name,type,lat,lon,elev,country}, … ] }, … ] }
     * Treffer sind auf den gewaehlten Suchraum und die Punktarten begrenzt.
     */
    public function resolve(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Oeffentlich zugaenglich (siehe security.yaml) – kein Login noetig.
        $conn = $em->getConnection();

        $queries = $request->request->all('q');
        if (!is_array($queries)) {
            $queries = [];
        }
        $region = (string) $request->request->get('region', 'de');

        // Punktarten: A (Flugplaetze), W (Waypoints)
        $typesRaw = strtoupper((string) $request->request->get('types', 'AW'));
        $types = array_values(array_filter(['A', 'W'], static fn ($t) => str_contains($typesRaw, $t)));
        if (!$types) {
            $types = ['A', 'W'];
        }
        $typePh = implode(',', array_fill(0, count($types), '?'));

        // Suchraum: de = Deutschland (Country GM), eu = Europa (Continent 3+8), all = weltweit
        $regSql = '';
        $regP = [];
        if ($region === 'de') {
            $regSql = ' AND Country = ?';
            $regP = ['GM'];
        } elseif ($region === 'eu') {
            $regSql = " AND Continent IN ('3','8')";
        }

        $out = [];
        foreach ($queries as $raw) {
            $q = strtoupper(trim((string) $raw));
            if ($q === '') {
                $out[] = ['candidates' => []];
                continue;
            }

            // 1) exakter ICAO-Code
            $rows = $conn->fetchAllAssociative(
                "SELECT ICAO, Airport, Type, sLat, sLong, ELEV, Country FROM tools_airports "
                . "WHERE Type IN ($typePh) AND ICAO = ?$regSql "
                . "ORDER BY CASE WHEN Type = 'A' THEN 0 ELSE 1 END LIMIT 8",
                array_merge($types, [$q], $regP)
            );
            // 2) sonst Teilsuche ICAO/Name (Praefix + Flugplaetze bevorzugt)
            if (!$rows && mb_strlen($q) >= 2) {
                $rows = $conn->fetchAllAssociative(
                    "SELECT ICAO, Airport, Type, sLat, sLong, ELEV, Country FROM tools_airports "
                    . "WHERE Type IN ($typePh) AND (ICAO LIKE ? OR Airport LIKE ?)$regSql "
                    . "ORDER BY (ICAO = ?) DESC, (ICAO LIKE ?) DESC, (Airport LIKE ?) DESC, "
                    . "CASE WHEN Type = 'A' THEN 0 ELSE 1 END, ICAO ASC, CHAR_LENGTH(Airport) LIMIT 60",
                    array_merge($types, ['%' . $q . '%', '%' . $q . '%'], $regP, [$q, $q . '%', $q . '%'])
                );
            }

            $cands = [];
            $seen = [];
            foreach ($rows as $r) {
                $lat = NavCalc::parseCoordinate((string) $r['sLat']);
                $lon = NavCalc::parseCoordinate((string) $r['sLong']);
                if ($lat === null || $lon === null) {
                    continue;
                }
                // Doppelte Datensaetze je ICAO (mehrere DAFIF-Zeilen pro Platz) zusammenfassen;
                // Punkte ohne ICAO ueber Name+Koordinaten unterscheiden.
                $ic = (string) ($r['ICAO'] ?? '');
                $dk = $ic !== '' ? 'I:' . $ic : 'N:' . ($r['Airport'] ?? '') . ':' . round($lat, 3) . ':' . round($lon, 3);
                if (isset($seen[$dk])) {
                    continue;
                }
                $seen[$dk] = true;
                $cands[] = [
                    'icao'    => (string) ($r['ICAO'] ?? ''),
                    'name'    => (string) ($r['Airport'] ?? ''),
                    'type'    => (string) ($r['Type'] ?? ''),
                    'lat'     => $lat,
                    'lon'     => $lon,
                    'elev'    => ($r['ELEV'] !== null && $r['ELEV'] !== '') ? (int) $r['ELEV'] : null,
                    'country' => (string) ($r['Country'] ?? ''),
                ];
            }
            // Fuer die Auswahl auf eine handhabbare Zahl begrenzen (nach Dedup).
            $out[] = ['candidates' => array_slice($cands, 0, 25)];
        }

        return new JsonResponse(['results' => $out]);
    }
}
