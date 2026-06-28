<?php

namespace App\Controller;

use App\Entities\Airfields;
use App\Entities\Bookings;
use App\Entities\FlightPurposes;
use App\Entities\Planes;
use App\Entities\Users;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Vertikale Scheibe fuer das neue Web-Design (Variante A, Sidebar).
 *
 * Bewusst eigener Controller + eigene Templates (templates/modern/) + eigene
 * Route (config/routes/modern.yaml), damit die bestehenden Seiten voellig
 * unangetastet bleiben. Verwendet die bestehende Datenlogik
 * (Bookings::GetBookingsForGeneralView) und kombiniert die heute getrennten
 * Menuepunkte zu kombinierbaren Filtern (Zeitraum/Umfang/Zweck/Gruppieren/Suche).
 */
class ModernPreviewController extends AbstractController
{
    /** Zeitfenster (DB-Fetch) – wie generalview-Standardansichten + thismonth. */
    private const TIME_COMMANDS = ['date', 'today', 'tomorrow', 'thisweek', 'weekafter', 'thisweekend', 'nextweekend', 'thismonth'];
    private const GROUPINGS     = ['datum', 'flugzeug', 'fluglehrer', 'nutzer'];
    private const ZWECKE        = ['alle', 'charter', 'schulung', 'wartung'];
    private const UMFAENGE      = ['alle', 'meine', 'historie'];
    private const MONTHS_DE     = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];
    private const WEEKDAYS_DE   = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    public function bookings(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $user = $loggedin_user;

        // --- Filter aus der URL (mit Whitelist-Absicherung) ---
        $zeitraum   = $request->query->get('zeitraum', 'date');
        $umfang     = $request->query->get('umfang', 'alle');
        $zweck      = $request->query->get('zweck', 'alle');
        $gruppieren = $request->query->get('gruppieren', 'datum');
        $q          = trim((string) $request->query->get('q', ''));
        if (!in_array($zeitraum, self::TIME_COMMANDS, true)) { $zeitraum = 'date'; }
        if (!in_array($umfang, self::UMFAENGE, true))        { $umfang = 'alle'; }
        if (!in_array($zweck, self::ZWECKE, true))           { $zweck = 'alle'; }
        if (!in_array($gruppieren, self::GROUPINGS, true))   { $gruppieren = 'datum'; }

        // --- DB-Fetch: Historie -> eigene Vergangenheit (inkl. FI-Fluege);
        //     sonst Zeitfenster (alle) ---
        $command = $umfang === 'historie' ? 'own_fi_history' : $zeitraum;
        $rows = Bookings::GetBookingsForGeneralView($em, $command, $user->getClientid(), $user->getId());

        // --- Post-Filter (kombinierbar): Umfang=meine, Zweck, Suche ---
        // "meine" = selbst gebucht ODER als Fluglehrer zugewiesen. Der FI steht in
        // der Zeile als Name -> eigenen Namen ueber dieselbe Funktion holen (gleiches Format).
        $needle = mb_strtolower($q);
        $myId   = (int) $user->getId();
        $myName = Users::GetUserName($em, $user->getClientid(), $myId);
        $rows = array_values(array_filter($rows, function (array $r) use ($umfang, $zweck, $needle, $myId, $myName) {
            if ($umfang === 'meine'
                && (int) $r['userid'] !== $myId
                && ($r['flightinstructor'] ?? '') !== $myName) {
                return false;
            }
            if ($zweck === 'schulung' && empty($r['isflighttraining'])) {
                return false;
            }
            if ($zweck === 'charter' && stripos((string) $r['flightpurpose'], 'charter') === false) {
                return false;
            }
            if ($zweck === 'wartung' && stripos((string) $r['flightpurpose'], 'wartung') === false) {
                return false;
            }
            if ($needle !== '') {
                $hay = mb_strtolower(($r['user'] ?? '') . ' ' . ($r['flugzeug'] ?? '') . ' ' . ($r['description'] ?? '') . ' ' . ($r['flightinstructor'] ?? ''));
                if (mb_strpos($hay, $needle) === false) {
                    return false;
                }
            }
            return true;
        }));

        // --- Gruppieren: nach Schluessel stabil sortieren (PHP8: usort stabil ->
        //     chronologische Reihenfolge innerhalb der Gruppe bleibt erhalten) ---
        if ($gruppieren !== 'datum') {
            $key = ['flugzeug' => 'flugzeug', 'fluglehrer' => 'flightinstructor', 'nutzer' => 'user'][$gruppieren];
            usort($rows, fn (array $a, array $b) => strcasecmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? '')));
        }

        $response = $this->render('modern/bookings.html.twig', [
            'bookings'   => $rows,
            'zeitraum'   => $zeitraum,
            'umfang'     => $umfang,
            'zweck'      => $zweck,
            'gruppieren' => $gruppieren,
            'q'          => $q,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /**
     * Auslastungs-Matrix (Flugzeuge x Tage) – ein Menuepunkt, oben Umschalter
     * 14 Tage / Monat. Nutzt die bestehende Logik Bookings::GetBookingsForAllPlanes
     * (Auslastungsfarbe pro Flugzeug/Tag) + Planes::GetAllPlanesForMonthview.
     */
    public function overview(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $tz = new \DateTimeZone('Europe/Berlin');

        $ansicht = $request->query->get('ansicht', 'monat');
        if (!in_array($ansicht, ['monat', '14tage'], true)) { $ansicht = 'monat'; }
        $off = (int) $request->query->get('off', 0);

        if ($ansicht === '14tage') {
            $start = new \DateTime('today', $tz);
            if ($off !== 0) { $start->modify(($off > 0 ? '+' : '') . $off . ' days'); }
            $duration = 15;
            $end = (clone $start)->modify('+' . ($duration - 1) . ' days');
            $label = $start->format('d.m.') . ' – ' . $end->format('d.m.Y');
            $prevOff = $off - $duration;
            $nextOff = $off + $duration;
        } else {
            $start = new \DateTime('first day of this month 00:00', $tz);
            if ($off !== 0) { $start->modify(($off > 0 ? '+' : '') . $off . ' months'); }
            $duration = (int) $start->format('t');
            $label = self::MONTHS_DE[(int) $start->format('n')] . ' ' . $start->format('Y');
            $prevOff = $off - 1;
            $nextOff = $off + 1;
        }

        $planes   = Planes::GetAllPlanesForMonthview($em, $clientid);
        $bookings = Bookings::GetBookingsForAllPlanes($em, $start, $duration, $clientid);

        // Zellen je Flugzeug (Reihenfolge = Datum, wie von der Methode geliefert)
        $cells = [];
        foreach (($bookings ?? []) as $b) {
            $cells[$b['plane']][] = ['color' => $b['color'], 'tooltip' => $b['tooltip'], 'date' => $b['bookingdate']];
        }

        // Tages-Header
        $wd = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $todayStr = (new \DateTime('today', $tz))->format('Y-m-d');
        $days = [];
        $cur = clone $start;
        for ($i = 0; $i < $duration; $i++) {
            $dow = (int) $cur->format('N');
            $days[] = [
                'num'     => (int) $cur->format('j'),
                'wd'      => $wd[$dow - 1],
                'wend'    => $dow === 6 ? 'sat' : ($dow === 7 ? 'sun' : ''),
                'today'   => $cur->format('Y-m-d') === $todayStr,
                'iso'     => $cur->format('Y-m-d'),
            ];
            $cur->modify('+1 day');
        }

        $response = $this->render('modern/overview.html.twig', [
            'ansicht' => $ansicht,
            'label'   => $label,
            'prevOff' => $prevOff,
            'nextOff' => $nextOff,
            'planes'  => $planes,
            'cells'   => $cells,
            'days'    => $days,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /**
     * Tagesansicht: Reservierungen eines Flugzeugs an einem Tag, als Karten
     * im Stil des Mobil-Frontends. Aufruf aus der Auslastungs-Matrix
     * (?plane=&date=YYYY-MM-DD). Nutzt Bookings::GetBookingsForPlaneAndDate.
     */
    public function day(Request $request, UserInterface $loggedin_user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $tz = new \DateTimeZone('Europe/Berlin');

        $planeId = (int) $request->query->get('plane', 0);
        $date = \DateTime::createFromFormat('Y-m-d', (string) $request->query->get('date', ''), $tz);
        if (!$date) { $date = new \DateTime('today', $tz); }
        $date->setTime(0, 0, 0);

        $planeName = $planeId ? Planes::GetPlaneNameAndKennung($em, $clientid, $planeId) : '';
        $bookings = Bookings::GetBookingsForPlaneAndDate(
            $em, (int) $date->format('j'), (int) $date->format('n'), (int) $date->format('Y'), $clientid, $planeId
        );

        $response = $this->render('modern/day.html.twig', [
            'planeId'   => $planeId,
            'planeName' => $planeName,
            'label'     => self::WEEKDAYS_DE[(int) $date->format('N')] . ', ' . $date->format('d.m.Y'),
            'prev'      => (clone $date)->modify('-1 day')->format('Y-m-d'),
            'next'      => (clone $date)->modify('+1 day')->format('Y-m-d'),
            'bookings'  => $bookings,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Einzelne Buchung im Detail (modern). Nutzt Bookings::GetBookingDetails. */
    public function booking(int $id, UserInterface $loggedin_user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $data = Bookings::GetBookingDetails($em, $loggedin_user->getClientid(), $id, $loggedin_user);
        if ($data === null) {
            throw $this->createNotFoundException('Buchung nicht gefunden.');
        }

        $response = $this->render('modern/booking.html.twig', $data);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /**
     * Reservieren-Maske (modern, am Mobil-Frontend orientiert) – plus die
     * Felder aus dem klassischen Web-Formular, die der PWA fehlen:
     * "Reserviert fuer" (createdbyuserid), E-Mail-Info intern (emailinfoi),
     * E-Mail-Info extern (emailinfoe). Reine Maske; Speichern folgt als
     * naechste Scheibe (Buchungsanlage mit Konflikt-/Lizenzpruefung).
     */
    public function reserve(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $tz = new \DateTimeZone('Europe/Berlin');

        // Vorbelegung aus der Tagesansicht (Flugzeug/Datum), falls vorhanden
        $preAircraft = (int) $request->query->get('plane', 0);
        $preDate = \DateTime::createFromFormat('Y-m-d', (string) $request->query->get('date', ''), $tz);
        $today = ($preDate ?: new \DateTime('today', $tz))->format('Y-m-d');

        $response = $this->render('modern/reserve.html.twig', [
            'aircraft'    => Planes::GetAllPlanesForListbox($em, $clientid),
            'instructors' => Users::GetAllFlightinstructorsForListbox($em, $clientid),
            'airfields'   => Airfields::GetAllAirportsForListbox($em),
            'purposes'    => FlightPurposes::GetFlightPuposeArray($em),
            'users'       => Users::GetAllUsersForListbox($em, $clientid),
            'mailusers'   => Users::GetAllUsersForMailListbox($em, $clientid, Users::const_Buchungsmail),
            'myId'        => $loggedin_user->getId(),
            'preAircraft' => $preAircraft,
            'today'       => $today,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }
}
