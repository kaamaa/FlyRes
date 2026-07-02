<?php

namespace App\Controller;

use App\Tools\NavCalc;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VFR-Flugplanung (erster Prototyp im modernen Frontend). Wegpunkte (ICAO) aus
 * der bestehenden Nav-Datenbank (tools_airports); je Streckenabschnitt werden
 * Distanz, rechtweisender Kurs (TC), Steuerkurs (TH), Grundgeschwindigkeit (GS),
 * Zeit und Sprit berechnet (NavCalc). Der Pfad ist fuer alle angemeldeten
 * Piloten erreichbar; der Menue-Eintrag wird (vorerst) nur Global-Admins gezeigt.
 */
class FlightPlanController extends AbstractController
{
    public function plan(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $conn = $em->getConnection();

        $tas       = max(1.0, (float) $request->request->get('tas', 100));
        $windDir   = fmod((float) $request->request->get('wind_dir', 0) + 360, 360);
        $windSpeed = max(0.0, (float) $request->request->get('wind_speed', 0));
        $fuelRate  = max(0.0, (float) $request->request->get('fuel_rate', 25));

        // Eine Eingabezeile je Wegpunkt (Array). Rohliste fuer das Neu-Rendern der
        // Zeilen, die nicht-leeren Eintraege werden fuer die Berechnung aufgeloest.
        $wpRaw = $request->request->all('waypoints');
        if (!is_array($wpRaw)) {
            $wpRaw = [];
        }
        $wpInputs = array_map(static fn ($s) => strtoupper(trim((string) $s)), $wpRaw);

        $points = [];
        foreach ($wpInputs as $tok) {
            if ($tok === '') {
                continue;   // leere Zeilen werden uebersprungen (keine Luecken-Fehler)
            }
            $row = $conn->fetchAssociative(
                "SELECT ICAO, Airport, sLat, sLong, Type FROM tools_airports WHERE ICAO = ? "
                . "ORDER BY CASE WHEN Type = 'A' THEN 0 ELSE 1 END LIMIT 1",
                [$tok]
            );
            if ($row) {
                $lat = NavCalc::parseCoordinate((string) $row['sLat']);
                $lon = NavCalc::parseCoordinate((string) $row['sLong']);
                $points[] = ['input' => $tok, 'found' => ($lat !== null && $lon !== null),
                             'icao' => $row['ICAO'], 'name' => $row['Airport'], 'type' => $row['Type'],
                             'lat' => $lat, 'lon' => $lon];
            } else {
                $points[] = ['input' => $tok, 'found' => false, 'icao' => $tok, 'name' => null,
                             'type' => null, 'lat' => null, 'lon' => null];
            }
        }

        $fmtTime = static function (float $min): string {
            $m = (int) round($min);
            return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
        };

        // Streckenabschnitte (Legs) zwischen aufeinanderfolgenden Punkten.
        $legs = [];
        $totDist = 0.0; $totTime = 0.0; $totFuel = 0.0;
        $ok = true;
        for ($i = 1; $i < count($points); $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $leg = ['from' => $a['icao'], 'to' => $b['icao'], 'error' => null];

            if (!$a['found'] || !$b['found']) {
                $leg['error'] = 'Wegpunkt nicht gefunden';
                $ok = false;
                $legs[] = $leg;
                continue;
            }

            $dist = NavCalc::distanceNm($a['lat'], $a['lon'], $b['lat'], $b['lon']);
            $tc   = NavCalc::bearing($a['lat'], $a['lon'], $b['lat'], $b['lon']);
            $leg['dist'] = $dist;
            $leg['tc']   = $tc;

            $wt = NavCalc::windTriangle($tc, $tas, $windDir, $windSpeed);
            if ($wt === null) {
                $leg['error'] = 'Wind zu stark – Abschnitt nicht fliegbar';
                $ok = false;
                $legs[] = $leg;
                continue;
            }

            $timeMin = $dist / $wt['gs'] * 60;
            $fuel    = $timeMin / 60 * $fuelRate;
            $leg['th']      = $wt['hdg'];
            $leg['gs']      = $wt['gs'];
            $leg['time']    = $timeMin;
            $leg['timeStr'] = $fmtTime($timeMin);
            $leg['fuel']    = $fuel;

            $totDist += $dist; $totTime += $timeMin; $totFuel += $fuel;
            $legs[] = $leg;
        }

        return $this->render('modern/flightplan.html.twig', [
            'wpInputs' => $wpInputs,
            'tas' => $tas, 'windDir' => $windDir, 'windSpeed' => $windSpeed, 'fuelRate' => $fuelRate,
            'points' => $points, 'legs' => $legs,
            'totDist' => $totDist, 'totTime' => $totTime, 'totTimeStr' => $fmtTime($totTime),
            'totFuel' => $totFuel, 'ok' => $ok,
        ]);
    }
}
