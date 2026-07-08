<?php

namespace App\Tools;

/**
 * Navigations-Rechnungen fuer die VFR-Flugplanung: Grosskreis-Distanz/-Kurs und
 * Winddreieck. Neu implementiert (Standard-Luftfahrtmathematik) nach dem Vorbild
 * des alten flugschule-worms.net-Tools (Class_NavTools). Bewusst als eigenstaendige
 * Utility-Klasse ohne Framework-Abhaengigkeit.
 */
class NavCalc
{
    /** Erdradius in NM (1 NM = 1 Bogenminute -> 360*60/(2*pi)). */
    private const R_NM = 3437.7467707849396;

    /**
     * DAFIF-Koordinatenstring (z. B. "N49362344" = Breite, "E008220624" = Laenge)
     * in Dezimalgrad. Format: Richtung + Grad + Minuten + Sekunden(.hundertstel).
     * Breite = 9 Zeichen (2 Grad), Laenge = 10 Zeichen (3 Grad). Gibt null bei
     * ungueltiger Eingabe.
     */
    public static function parseCoordinate(?string $s): ?float
    {
        if ($s === null) {
            return null;
        }
        // In den Daten kommen gelegentlich "/" statt "0" vor.
        $c = str_replace('/', '0', trim($s));
        $len = strlen($c);
        $dir = strtoupper(substr($c, 0, 1));

        if ($len >= 10) {            // Laenge (Longitude)
            $deg = (int) substr($c, 1, 3);
            $min = (int) substr($c, 4, 2);
            $sec = (float) (substr($c, 6, 2) . '.' . substr($c, 8, 2));
        } elseif ($len === 9) {      // Breite (Latitude)
            $deg = (int) substr($c, 1, 2);
            $min = (int) substr($c, 3, 2);
            $sec = (float) (substr($c, 5, 2) . '.' . substr($c, 7, 2));
        } else {
            return null;
        }

        $dec = $deg + $min / 60 + $sec / 3600;
        if ($dir === 'S' || $dir === 'W') {
            $dec = -$dec;
        }
        return $dec;
    }

    /** Grosskreis-Distanz zwischen zwei Punkten in NM (Haversine). */
    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return self::R_NM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Rechtweisender Anfangskurs (True Course) 0..360 Grad. */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lon2 - $lon1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /**
     * Winddreieck: aus gewuenschtem Kurs (TC), Eigengeschwindigkeit (TAS),
     * Windrichtung (woher der Wind kommt) und Windstaerke die Grundgeschwindigkeit
     * (GS) und den Steuerkurs (TH) berechnen. Gibt null zurueck, wenn nicht
     * loesbar (Wind zu stark) oder die Grundgeschwindigkeit <= 0 ist.
     *
     * @return array{gs: float, hdg: float}|null
     */
    public static function windTriangle(float $tc, float $tas, float $windDir, float $windSpeed): ?array
    {
        if ($tas <= 0) {
            return null;
        }
        $wtc = deg2rad($windDir - $tc);           // Windwinkel relativ zum Kurs
        $swc = ($windSpeed / $tas) * sin($wtc);   // Sinus des Vorhaltewinkels
        if (abs($swc) > 1.0) {
            return null;                          // Wind zu stark -> nicht fliegbar
        }
        $wca = asin($swc);                        // Vorhaltewinkel (rad)
        $gs  = $tas * cos($wca) - $windSpeed * cos($wtc);
        if ($gs <= 0) {
            return null;
        }
        $hdg = fmod($tc + rad2deg($wca) + 360.0, 360.0);
        return ['gs' => $gs, 'hdg' => $hdg];
    }
}
