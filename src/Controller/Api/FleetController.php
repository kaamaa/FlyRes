<?php

namespace App\Controller\Api;

use App\Entities\Planes;
use App\Entities\Bookings;
use App\TimeFunctions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Monatsuebersicht der Flotte fuer die PWA.
 *
 *   GET /api/fleet?month=YYYY-MM
 *
 * Liefert je Flugzeug einen Tagesstatus (frei/teils/voll) fuer den ganzen
 * Monat als Ampel.
 *
 * Bewusst NICHT ueber Bookings::GetBookingsForAllPlanes: jenes feuert pro
 * Flugzeug UND pro Tag eine eigene DB-Abfrage (~Flugzeuge x 30 Queries) und
 * lief ins 30-Sekunden-Timeout. Hier werden stattdessen alle Buchungen des
 * Monats in EINER Query geladen und der Ampelstatus in PHP berechnet – das
 * Tagesfenster (Sonnen-/Oeffnungszeiten) kommt aus den reinen Rechen-Helfern
 * TimeFunctions::GetDayStart/GetDayEnd (keine DB).
 */
class FleetController extends ApiController
{
    /** GET /api/fleet */
    public function month(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $clientid = $user->getClientid();

        $tzo = new \DateTimeZone('Europe/Berlin');

        // --- Monat bestimmen (Default: aktueller Monat) ---
        $monthStr = (string) $request->query->get('month', '');
        $first = \DateTime::createFromFormat('Y-m-d', $monthStr . '-01', $tzo);
        if (!$first || $first->format('Y-m') !== $monthStr) {
            $first = new \DateTime('first day of this month', $tzo);
        }
        $first->setTime(0, 0, 0);

        $year    = (int) $first->format('Y');
        $month   = (int) $first->format('n');
        $maxdays = (int) $first->format('t');

        $prev = (clone $first)->modify('first day of previous month')->format('Y-m');
        $next = (clone $first)->modify('first day of next month')->format('Y-m');

        $fmt = new \IntlDateFormatter('de_DE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tzo, \IntlDateFormatter::GREGORIAN, 'LLLL yyyy');
        $label = $fmt->format($first);

        $todayYmd = (new \DateTime('now', $tzo))->format('Y-m-d');
        $wd = TimeFunctions::WEEKDAYS_SHORT;

        // --- Tage des Monats + nutzbares Tagesfenster je Tag (einmal berechnen) ---
        // Fenstergrenzen (sTs/eTs) wie im Web aus GetDayStart/GetDayEnd (halbe Stunden,
        // gedeckelt auf Flugplatz 09:00–20:30) für das Clamping mehrtägiger Buchungen.
        // Der Nenner ist die GetDaylight-Sekundenzahl (ungerundete Dämmerung, gedeckelt) –
        // exakt wie die Web-Monatsansicht.
        $days = [];
        $window = []; // day => [startTs, endTs, daylightSeconds]
        for ($d = 1; $d <= $maxdays; $d++) {
            $dt = (clone $first)->setDate($year, $month, $d);
            $dow = (int) $dt->format('N');
            $ymd = $dt->format('Y-m-d');
            $days[] = [
                'day'     => $d,
                'date'    => $ymd,
                'wd'      => $wd[$dow],
                'weekend' => $dow >= 6,
                'today'   => $ymd === $todayYmd,
            ];

            $ds = TimeFunctions::GetDayStart($dt);
            $de = TimeFunctions::GetDayEnd($dt);
            $sTs = (clone $dt)->setTime($ds[0], $ds[1])->getTimestamp();
            $eTs = (clone $dt)->setTime($de[0], $de[1])->getTimestamp();
            $daylight = TimeFunctions::GetDaylight($month, $d, $year);
            $window[$d] = [$sTs, $eTs, max(1, $daylight)];
        }

        // --- Alle Buchungen des Monats in EINER Abfrage ---
        $monthStart = (clone $first)->setTime(0, 0, 0);
        $monthEnd   = (clone $first)->modify('first day of next month')->setTime(0, 0, 0);
        $dql = "SELECT b FROM App\Entity\FresBooking b
                WHERE b.clientid = :cid
                  AND b.itemstart < :end AND b.itemstop > :start
                  AND b.status <> 'storniert'
                  AND b.status <> 'flugzeug_geloescht'
                  AND b.status <> 'user_geloescht'";
        $bookings = $em->createQuery($dql)
            ->setParameters(['cid' => $clientid, 'start' => $monthStart, 'end' => $monthEnd])
            ->getResult();

        // --- Belegte Sekunden je Flugzeug+Tag aufsummieren (exakt wie Desktop) ---
        // Eintägige Buchung: rohe Dauer. Mehrtägige: Starttag = Fensterende−Start,
        // Endtag = Ende−Fensteranfang, Zwischentag = volles Fenster. (Bookings::GetBookingsForAllPlanes)
        $monthYm = $first->format('Y-m');
        $booked = []; // planeId => [day => seconds]
        foreach ($bookings as $b) {
            $pid    = $b->getAircraftid();
            $bStart = (clone $b->getItemstart())->setTimezone($tzo);
            $bStop  = (clone $b->getItemstop())->setTimezone($tzo);
            $bStartTs = $bStart->getTimestamp();
            $bStopTs  = $bStop->getTimestamp();
            $startYmd = $bStart->format('Y-m-d');
            $stopYmd  = $bStop->format('Y-m-d');
            $single   = ($startYmd === $stopYmd);
            // Tag-im-Monat von Start/Ende (0, falls in anderem Monat)
            $startDom = ($bStart->format('Y-m') === $monthYm) ? (int) $bStart->format('j') : 0;
            $stopDom  = ($bStop->format('Y-m')  === $monthYm) ? (int) $bStop->format('j')  : 0;

            foreach ($window as $d => $w) {
                $dYmd = sprintf('%04d-%02d-%02d', $year, $month, $d);
                if ($dYmd < $startYmd || $dYmd > $stopYmd) {
                    continue; // Buchung berührt diesen Kalendertag nicht
                }
                if ($single) {
                    $sec = $bStopTs - $bStartTs;          // eintägig: rohe Dauer
                } elseif ($d === $startDom) {
                    $sec = $w[1] - $bStartTs;             // Starttag: bis Fensterende
                } elseif ($d === $stopDom) {
                    $sec = $bStopTs - $w[0];              // Endtag: ab Fensteranfang
                } else {
                    $sec = $w[1] - $w[0];                 // Zwischentag: volles Fenster
                }
                if ($sec > 0) {
                    $booked[$pid][$d] = ($booked[$pid][$d] ?? 0) + $sec;
                }
            }
        }

        // --- Flugzeuge (nur aktive) + Tagesstatus ---
        $aircraft = [];
        foreach (Planes::GetAllPlanesForMonthview($em, $clientid) as $p) {
            $pid = $p['planeID'];
            $statusDays = [];
            for ($d = 1; $d <= $maxdays; $d++) {
                $statusDays[] = self::levelFromUsage($booked[$pid][$d] ?? 0, $window[$d][2]);
            }
            $aircraft[] = [
                'id'       => $pid,
                'type'     => $p['type'],
                'callsign' => $p['kennung'],
                'days'     => $statusDays,
            ];
        }

        return $this->json([
            'month'    => $first->format('Y-m'),
            'label'    => $label,
            'prev'     => $prev,
            'next'     => $next,
            'days'     => $days,
            'aircraft' => $aircraft,
        ]);
    }

    /**
     * GET /api/fleet/day?aircraft=ID&date=YYYY-MM-DD
     *
     * Tagesdetail eines Flugzeugs: vorhandene Reservierungen dieses Tages
     * (Uhrzeit, Pilot, Zweck) – Grundlage fuer das Tap-Sheet der Uebersicht.
     * Der Ampelstatus wird NICHT zurueckgegeben; den kennt das Frontend bereits
     * aus der Monatsabfrage.
     */
    public function day(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $clientid = $user->getClientid();
        $tzo = new \DateTimeZone('Europe/Berlin');

        $aircraftId = (int) $request->query->get('aircraft', 0);   // SF8: getInt() wirft bei nicht-numerisch
        if (!$aircraftId) {
            return $this->json(['error' => 'aircraft_required'], 400);
        }

        $dateStr = (string) $request->query->get('date', '');
        $dt = \DateTime::createFromFormat('Y-m-d', $dateStr, $tzo);
        if (!$dt || $dt->format('Y-m-d') !== $dateStr) {
            return $this->json(['error' => 'invalid_date'], 400);
        }
        $dt->setTime(0, 0, 0);

        $plane = $em->getRepository('App\Entity\FresAircraft')->findOneBy(['id' => $aircraftId, 'clientid' => $clientid]);
        if (!$plane) {
            return $this->json(['error' => 'not_found'], 404);
        }

        $fmt = new \IntlDateFormatter('de_DE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $tzo, \IntlDateFormatter::GREGORIAN, 'EEEE, d. MMMM yyyy');
        $dayYmd = $dt->format('d.m.Y');

        $rows = Bookings::GetBookingsForPlaneAndDate(
            $em, (int) $dt->format('j'), (int) $dt->format('n'), (int) $dt->format('Y'), $clientid, $aircraftId
        ) ?: [];

        $bookings = [];
        foreach ($rows as $r) {
            // GetBookingsForPlaneAndDate liefert start/end als 'd.m.Y H:i'.
            [$sDay, $sTime] = array_pad(explode(' ', $r['start']), 2, '');
            [$eDay, $eTime] = array_pad(explode(' ', $r['end']), 2, '');
            $bookings[] = [
                'id'          => $r['bookingid'],
                'start'       => $sTime,
                'end'         => $eTime,
                // Bei mehrtaegigen Buchungen das abweichende Datum mitschicken.
                'startDay'    => $sDay !== $dayYmd ? $sDay : null,
                'endDay'      => $eDay !== $dayYmd ? $eDay : null,
                'pilot'       => $r['user'],
                'purpose'     => $r['flightpurpose'],
                'isTraining'  => (bool) $r['isflighttraining'],
                'instructor'  => $r['flightinstructor'] ?: null,
                'description' => $r['description'],
            ];
        }

        return $this->json([
            'aircraft' => [
                'id'       => $aircraftId,
                'callsign' => $plane->getKennung(),
                'type'     => $plane->getAircraft(),
            ],
            'date'     => $dt->format('Y-m-d'),
            'label'    => $fmt->format($dt),
            'bookings' => $bookings,
        ]);
    }

    /**
     * Tagesauslastung -> 6-stufige Skala (0..5), exakt wie die Web-Monatsansicht:
     *   prozent = (int)(100 / daylight * belegt), gedeckelt auf 0..100,
     *   color_value = (int)(prozent / 10), mind. 1 bei jeder Belegung.
     * color_value 0..10 wird auf die 6 Stufen abgebildet:
     *   0 leer · 1–2 wenig · 3–5 mittel · 6–8 voll · 9 sehrvoll · 10 ausgebucht.
     */
    private static function levelFromUsage(int $bookedSeconds, int $daylightSeconds): int
    {
        $pct = (int) (100 / $daylightSeconds * $bookedSeconds);
        if ($pct > 100) $pct = 100;
        if ($pct < 0)   $pct = 0;
        $cv = (int) ($pct / 10);
        if ($pct > 0 && $cv === 0) $cv = 1;

        if ($cv >= 10) return 5; // ausgebucht
        if ($cv >= 9)  return 4; // sehrvoll
        if ($cv >= 6)  return 3; // voll
        if ($cv >= 3)  return 2; // mittel
        if ($cv >= 1)  return 1; // wenig
        return 0;                // leer
    }
}
