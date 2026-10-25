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
    /**
     * Oberflaechentexte fuer beide Sprachen. Da diese Seite rein clientseitig rechnet
     * (kein Form-Submit/Reload bei Parameteraenderung, siehe JS im Template), wird IMMER
     * auf Deutsch gerendert und die Sprachumschaltung passiert komplett im Browser (per
     * data-i18n-Attributen + diesem Woerterbuch, das als JSON mitgeliefert wird) - sonst
     * wuerden bei jedem Sprachwechsel alle eingetippten Wegpunkte/Parameter verloren gehen.
     * Gleiche Struktur/Idee wie Sunrise_SunsetController::sunriseTranslations(), nur fuer
     * beide Sprachen gleichzeitig statt nur fuer die jeweils aktive.
     */
    private function flightplanTranslations(): array
    {
        return [
            'de' => [
                'title' => 'VFR-Flugplanung', 'tab' => 'Flugplanung',
                'print' => '🖨 Als PDF speichern / drucken', 'print_short' => 'Drucken',
                'grp_aircraft' => 'Flugzeug & Wind', 'grp_search' => 'Suche', 'grp_add' => 'Zuschläge (min)',
                'tas' => 'TAS (kt)', 'fuel_rate' => 'Verbrauch (l/h)', 'wind_dir' => 'Wind aus (°)', 'wind_speed' => 'Wind (kt)',
                'variation' => 'Missweisung (°)', 'variation_title' => 'Ortsmissweisung (Ost = positiv). MC = TC − Missweisung.',
                'region' => 'Suchraum', 'region_de' => 'Deutschland', 'region_eu' => 'Europa', 'region_all' => 'Weltweit',
                'types' => 'Punktarten', 'types_aw' => 'Flugplätze + Waypoints', 'types_a' => 'Nur Flugplätze', 'types_w' => 'Nur Waypoints',
                'add_start' => 'Start/Rollen', 'add_land' => 'Anflug/Landung', 'add_alt' => 'Alternate',
                'th_wp' => 'Wegpunkt', 'th_name' => 'Name / Info', 'th_dist' => 'Distanz', 'th_tc' => 'Kurs TC', 'th_th' => 'Steuerkurs TH',
                'th_mc' => 'MC', 'th_mh' => 'MH', 'th_gs' => 'GS', 'th_time' => 'Zeit', 'th_fuel' => 'Sprit',
                'ins_row' => 'Zeile darunter einfügen', 'del_row' => 'Zeile löschen', 'placeholder' => 'ICAO / Name',
                'tot_dist' => 'Gesamt Strecke', 'f_legtime' => 'Streckenzeit', 'f_addtime' => 'Zuschläge (Start · Landung · Alternate)',
                'f_block' => 'Blockzeit gesamt', 'f_fuel' => 'Sprit gesamt (inkl. Zuschläge)',
                'hint' => 'Je Zeile ein Wegpunkt (ICAO <b>oder</b> Name). Werte rechts werden <b>automatisch</b> berechnet. Bei mehreren Treffern erscheint ein Auswahlfeld. MC/MH = missweisend (aus TC/TH − Missweisung). ＋ fügt eine Zeile ein, ✕ löscht, <b>Enter</b> springt weiter.',
                'disc' => '<b>Achtung:</b> Die verwendeten Navigationsdaten sind nicht auf dem aktuellen Stand (nur Flugplätze/Waypoints, Elevation; keine Frequenzen/Pisten). Ergebnisse vor Flugantritt anhand der offiziell gültigen Unterlagen (AIP, ICAO-Karten, Flughandbuch) überprüfen. Benutzung auf eigene Gefahr.',
                'print_title' => 'VFR-Flugplan',
                'not_found' => 'nicht gefunden', 'multi_match' => 'mehrere Treffer', 'more_refine' => 'weitere – Suche verfeinern',
                'elev' => 'Elev', 'wind_too_strong' => 'Wind zu stark – nicht fliegbar', 'wp_not_found' => 'Wegpunkt nicht gefunden',
                'incomplete' => 'unvollständig', 'waypoints_word' => 'Wegpunkte',
                'print_tas' => 'TAS', 'print_wind' => 'Wind', 'print_fuelrate' => 'Verbrauch', 'print_var' => 'Missweisung',
                'print_block' => 'Blockzeit', 'print_fuel' => 'Sprit', 'print_asof' => 'Stand',
            ],
            'en' => [
                'title' => 'VFR Flight Planning', 'tab' => 'Flight planning',
                'print' => '🖨 Save as PDF / print', 'print_short' => 'Print',
                'grp_aircraft' => 'Aircraft & wind', 'grp_search' => 'Search', 'grp_add' => 'Allowances (min)',
                'tas' => 'TAS (kt)', 'fuel_rate' => 'Fuel burn (l/h)', 'wind_dir' => 'Wind from (°)', 'wind_speed' => 'Wind (kt)',
                'variation' => 'Variation (°)', 'variation_title' => 'Local magnetic variation (East = positive). MC = TC − variation.',
                'region' => 'Search area', 'region_de' => 'Germany', 'region_eu' => 'Europe', 'region_all' => 'Worldwide',
                'types' => 'Point types', 'types_aw' => 'Airports + waypoints', 'types_a' => 'Airports only', 'types_w' => 'Waypoints only',
                'add_start' => 'Taxi/departure', 'add_land' => 'Approach/landing', 'add_alt' => 'Alternate',
                'th_wp' => 'Waypoint', 'th_name' => 'Name / Info', 'th_dist' => 'Distance', 'th_tc' => 'Track TC', 'th_th' => 'Heading TH',
                'th_mc' => 'MC', 'th_mh' => 'MH', 'th_gs' => 'GS', 'th_time' => 'Time', 'th_fuel' => 'Fuel',
                'ins_row' => 'Insert row below', 'del_row' => 'Delete row', 'placeholder' => 'ICAO / Name',
                'tot_dist' => 'Total distance', 'f_legtime' => 'Flight time', 'f_addtime' => 'Allowances (departure · landing · alternate)',
                'f_block' => 'Total block time', 'f_fuel' => 'Total fuel (incl. allowances)',
                'hint' => 'One waypoint per row (ICAO <b>or</b> name). Values on the right are calculated <b>automatically</b>. Multiple matches show a dropdown. MC/MH = magnetic (TC/TH − variation). ＋ inserts a row, ✕ deletes it, <b>Enter</b> jumps to the next row.',
                'disc' => '<b>Note:</b> The navigation data used is not current (airports/waypoints and elevation only; no frequencies/runways). Verify results against the officially valid documents (AIP, ICAO charts, aircraft flight manual) before flying. Use at your own risk.',
                'print_title' => 'VFR Flight Plan',
                'not_found' => 'not found', 'multi_match' => 'multiple matches', 'more_refine' => 'more – refine search',
                'elev' => 'Elev', 'wind_too_strong' => 'Wind too strong – not flyable', 'wp_not_found' => 'Waypoint not found',
                'incomplete' => 'incomplete', 'waypoints_word' => 'waypoints',
                'print_tas' => 'TAS', 'print_wind' => 'Wind', 'print_fuelrate' => 'Fuel burn', 'print_var' => 'Variation',
                'print_block' => 'Block time', 'print_fuel' => 'Fuel', 'print_asof' => 'As of',
            ],
        ];
    }

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
            'langDict'  => $this->flightplanTranslations(),
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
                    . "CASE WHEN Type = 'A' THEN 0 ELSE 1 END, ICAO ASC, CHAR_LENGTH(Airport) LIMIT 120",
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
            // Fuer die Auswahl auf eine handhabbare Zahl begrenzen; bei mehr Treffern
            // ein Flag setzen, damit das Frontend "Suche verfeinern" anzeigen kann.
            $cap = 25;
            $truncated = count($cands) > $cap;
            $out[] = ['candidates' => array_slice($cands, 0, $cap), 'truncated' => $truncated];
        }

        return new JsonResponse(['results' => $out]);
    }
}
