<?php

namespace App\Controller;

use App\Entities\Airfields;
use App\Entities\Bookings;
use App\Entities\FlightPurposes;
use App\Entities\Functions;
use App\Entities\Licenses;
use App\Entities\Notes;
use App\Entities\Planes;
use App\Entities\Users;
use App\Entity\FresAccounts;
use App\Entity\FresAircraft;
use App\Entity\FresAircrafttype;
use App\Entity\FresClient;
use App\Entity\FresNote;
use App\Entity\FresUserlicences;
use App\ViewHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

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

    /**
     * Persoenliches Dashboard (Login-Startseite). Bringt eigene Buchungen,
     * Lizenz-Status/Warnungen und Pinnwand zusammen; fuer Fluglehrer zusaetzlich
     * die eigenen Schulungstermine, fuer Admins club-weite Lizenz-Warnungen und
     * Kennzahlen. Nutzt die bestehende Datenlogik (Bookings/Notes/Planes/Users).
     */
    public function dashboard(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isFi     = Users::isFlightinstructor($em, $myId);
        $isAdmin  = $this->isGranted('ROLE_ADMIN');

        $tz    = new \DateTimeZone('Europe/Berlin');
        $now   = new \DateTime('now', $tz);
        $today = new \DateTime('today', $tz);
        $green = (clone $today)->modify('+12 months');
        $amber = (clone $today)->modify('+3 months');
        $wd    = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $statusOk = "b.status <> 'storniert' AND b.status <> 'flugzeug_geloescht' AND b.status <> 'user_geloescht'";

        $heute = self::WEEKDAYS_DE[(int) $today->format('N')] . ', ' . (int) $today->format('j')
               . '. ' . self::MONTHS_DE[(int) $today->format('n')] . ' ' . $today->format('Y');

        $kindOf = static function (string $label): string {
            if (stripos($label, 'chul') !== false)   { return 'schul'; }
            if (stripos($label, 'artung') !== false) { return 'wart'; }
            return 'charter';
        };
        $mapBooking = function ($b) use ($em, $clientid, $kindOf, $wd) {
            $start = $b->getItemstart();
            $stop  = $b->getItemstop();
            $label = (string) FlightPurposes::GetFlightpurpose($em, $b->getflightpurposeid());
            return [
                'id'       => $b->getId(),
                'tag'      => $wd[(int) $start->format('N') - 1] . ' ' . $start->format('d.m.'),
                'zeit'     => $start->format('H:i') . '–' . $stop->format('H:i'),
                'flugzeug' => Planes::GetPlaneNameAndKennung($em, $clientid, $b->getAircraftid()),
                'purpose'  => $label,
                'kind'     => $kindOf($label),
                'fi'       => $b->getFlightinstructor() ? Users::GetUserName($em, $clientid, $b->getFlightinstructor()) : '',
                'pilot'    => Users::GetUserName($em, $clientid, $b->getCreatedbyuserid()),
                'desc'     => trim((string) $b->getDescription()),
            ];
        };

        // --- Meine naechsten Buchungen ---
        $myBookings = array_map($mapBooking, $em->createQuery(
            "SELECT b FROM App\Entity\FresBooking b WHERE b.clientid = :cid AND b.createdbyuserid = :uid "
            . "AND b.itemstop >= :now AND $statusOk ORDER BY b.itemstart ASC"
        )->setParameter('cid', $clientid)->setParameter('uid', $myId)->setParameter('now', $now)
         ->setMaxResults(5)->getResult());

        // --- Schulungstermine (nur Fluglehrer) ---
        $fiBookings = [];
        if ($isFi) {
            $fiBookings = array_map($mapBooking, $em->createQuery(
                "SELECT b FROM App\Entity\FresBooking b WHERE b.clientid = :cid AND b.flightinstructor = :uid "
                . "AND b.itemstop >= :now AND $statusOk ORDER BY b.itemstart ASC"
            )->setParameter('cid', $clientid)->setParameter('uid', $myId)->setParameter('now', $now)
             ->setMaxResults(5)->getResult());
        }

        // --- Meine Lizenzen (Ampel + Warnung) ---
        $kpi = ['green' => 0, 'amber' => 0, 'red' => 0, 'inf' => 0];
        $myLicences = [];
        $warn = null;
        $licRows = $em->createQuery(
            "SELECT b FROM App\Entity\FresUserlicences b WHERE b.clientid = :cid AND b.accountid = :uid "
            . "AND (b.status <> 'geloescht' OR b.status IS NULL) ORDER BY b.validuntil ASC"
        )->setParameter('cid', $clientid)->setParameter('uid', $myId)->getResult();
        foreach ($licRows as $l) {
            $unlimited = (bool) $l->getValidunlimited();
            $vu = $unlimited ? null : $l->getValiduntil();
            if ($unlimited)        { $state = 'inf'; }
            elseif ($vu > $green)  { $state = 'green'; }
            elseif ($vu > $amber)  { $state = 'amber'; }
            else                   { $state = 'red'; }
            ++$kpi[$state];

            $expired = (!$unlimited && $vu < $today);
            $type = $l->getLicence();
            $cat  = $type ? trim((string) $type->getCategoryname()) : '';
            $long = $type ? trim((string) $type->getLongname()) : '';
            $myLicences[] = [
                'name'     => $cat !== '' ? $cat : ($long !== '' ? $long : 'Lizenz'),
                'full'     => ($cat !== '' && $long !== '' && $long !== $cat) ? $long : '',
                'state'    => $state,
                'badge'    => $unlimited ? 'unbegrenzt' : ($expired ? 'abgelaufen' : ($state === 'red' ? 'läuft bald ab' : 'gültig')),
                'validity' => $unlimited ? 'unbegrenzt gültig' : (($expired ? 'abgelaufen am ' : 'gültig bis ') . $vu->format('d.m.Y')),
                'sortkey'  => $unlimited ? PHP_INT_MAX : $vu->getTimestamp(),
            ];
            if ($state === 'red' && $warn === null) {
                $warn = [
                    'name'    => ($cat !== '' ? $cat : $long) . ($cat !== '' && $long !== '' && $long !== $cat ? ' ' . $long : ''),
                    'expired' => $expired,
                    'date'    => $vu->format('d.m.Y'),
                    'days'    => abs((int) $today->diff($vu)->format('%r%a')),
                ];
            }
        }
        usort($myLicences, fn (array $a, array $b) => $a['sortkey'] <=> $b['sortkey']);
        $myLicences = array_slice($myLicences, 0, 4);

        // --- Pinnwand (aktuelle Aushaenge) ---
        $notes = [];
        foreach (Notes::GetAllActiveNotesAsObject($em) as $n) {
            if ((int) $n->getClientid() !== (int) $clientid) { continue; }
            $text = trim((string) $n->getDescription());
            if (mb_strlen($text) > 130) { $text = mb_substr($text, 0, 130) . '…'; }
            $u = $n->getUser();
            $notes[] = [
                'header'  => $n->getHeader(),
                'text'    => $text,
                'author'  => $u ? trim($u->getFirstname() . ' ' . $u->getLastname()) : '',
                'validuntil' => $n->getValiduntil() ? $n->getValiduntil()->format('d.m.Y') : '',
                'sortkey' => $n->getCreateddate() ? $n->getCreateddate()->getTimestamp() : 0,
            ];
        }
        usort($notes, fn (array $a, array $b) => $b['sortkey'] <=> $a['sortkey']);
        $notes = array_slice($notes, 0, 3);

        // --- Admin: club-weite Lizenz-Warnungen + Kennzahlen ---
        $adminLic = [];
        $stats = [];
        if ($isAdmin) {
            $adminRows = $em->createQuery(
                "SELECT b FROM App\Entity\FresUserlicences b WHERE b.clientid = :cid "
                . "AND (b.status <> 'geloescht' OR b.status IS NULL) "
                . "AND (b.validunlimited = 0 OR b.validunlimited IS NULL) AND b.validuntil < :amber "
                . "ORDER BY b.validuntil ASC"
            )->setParameter('cid', $clientid)->setParameter('amber', $amber)->setMaxResults(8)->getResult();
            foreach ($adminRows as $l) {
                $vu = $l->getValiduntil();
                if (!$vu) { continue; }
                $expired = $vu < $today;
                $type = $l->getLicence();
                $cat  = $type ? trim((string) $type->getCategoryname()) : '';
                $adminLic[] = [
                    'user'     => Users::GetUserName($em, $clientid, (int) $l->getAccountid()),
                    'name'     => $cat !== '' ? $cat : ($type ? trim((string) $type->getLongname()) : 'Lizenz'),
                    'validity' => ($expired ? 'abgelaufen am ' : 'gültig bis ') . $vu->format('d.m.Y'),
                    'badge'    => $expired ? 'abgelaufen' : 'läuft bald ab',
                ];
            }

            $te = (clone $today)->setTime(23, 59, 59);
            $ws = (clone $today)->modify('monday this week')->setTime(0, 0, 0);
            $we = (clone $ws)->modify('+6 days')->setTime(23, 59, 59);
            $countBookings = function ($from, $to) use ($em, $clientid, $statusOk) {
                return (int) $em->createQuery(
                    "SELECT COUNT(b.id) FROM App\Entity\FresBooking b WHERE b.clientid = :cid "
                    . "AND b.itemstart <= :to AND b.itemstop >= :from AND $statusOk"
                )->setParameter('cid', $clientid)->setParameter('from', $from)->setParameter('to', $to)->getSingleScalarResult();
            };
            $scalar = function (string $dql, array $params) use ($em) {
                return (int) $em->createQuery($dql)->setParameters($params)->getSingleScalarResult();
            };
            $stats = [
                'bookToday'   => $countBookings($today, $te),
                'bookWeek'    => $countBookings($ws, $we),
                'users'       => $scalar("SELECT COUNT(b.id) FROM App\Entity\FresAccounts b WHERE b.clientid = :cid AND (b.status <> 'geloescht' OR b.status IS NULL)", ['cid' => $clientid]),
                'aircraft'    => $scalar("SELECT COUNT(b.id) FROM App\Entity\FresAircraft b WHERE b.clientid = :cid AND b.status <> 'geloescht' AND b.status <> 'inactive'", ['cid' => $clientid]),
                'expiredLic'  => $scalar("SELECT COUNT(b.id) FROM App\Entity\FresUserlicences b WHERE b.clientid = :cid AND (b.status <> 'geloescht' OR b.status IS NULL) AND (b.validunlimited = 0 OR b.validunlimited IS NULL) AND b.validuntil < :today", ['cid' => $clientid, 'today' => $today]),
                'locked'      => $scalar("SELECT COUNT(b.id) FROM App\Entity\FresAccounts b WHERE b.clientid = :cid AND b.islocked = 1 AND (b.status <> 'geloescht' OR b.status IS NULL)", ['cid' => $clientid]),
            ];
        }

        $response = $this->render('modern/dashboard.html.twig', [
            'vorname'    => $loggedin_user->getFirstname(),
            'heute'      => $heute,
            'warn'       => $warn,
            'myBookings' => $myBookings,
            'myLicences' => $myLicences,
            'kpi'        => $kpi,
            'notes'      => $notes,
            'isFi'       => $isFi,
            'fiBookings' => $fiBookings,
            'isAdmin'    => $isAdmin,
            'adminLic'   => $adminLic,
            'stats'      => $stats,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Kurzanleitung – Inhalte rollenabhaengig eingeblendet. */
    public function help(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');

        $response = $this->render('modern/help.html.twig', [
            'isFi'          => Users::isFlightinstructor($em, (int) $loggedin_user->getId()),
            'isAdmin'       => $this->isGranted('ROLE_ADMIN'),
            'isSysAdmin'    => $this->isGranted('ROLE_SYSTEM_ADMIN'),
            'isGlobalAdmin' => $this->isGranted('ROLE_GLOBAL_ADMIN'),
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

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

        $ansicht = $request->query->get('ansicht', '14tage');
        if (!in_array($ansicht, ['monat', '14tage'], true)) { $ansicht = '14tage'; }
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

    /** Schwelle "laeuft bald ab" in Tagen. */
    private const LICENCE_WARN_DAYS = 30;

    /**
     * Lizenzen-Liste (modern), nach Nutzer gruppiert, mit Status + Kennzahlen.
     * Berechtigungen wie im Bestand: scope "alle"/"abgelaufen" nur fuer ROLE_ADMIN,
     * sonst nur die eigenen Lizenzen.
     */
    public function licences(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $myId = (int) $loggedin_user->getId();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $scope = (string) $request->query->get('scope', 'meine');
        if (!$isAdmin || !in_array($scope, ['meine', 'alle', 'abgelaufen'], true)) {
            $scope = 'meine';   // Nicht-Admins immer nur eigene
        }

        // Direktlink aus der Nutzerverwaltung: alle Lizenzen eines Nutzers (nur Admin)
        $userFilter = $isAdmin ? (int) $request->query->get('user', 0) : 0;
        $filterUid = null;
        if ($userFilter > 0) { $filterUid = $userFilter; $scope = 'alle'; }
        elseif ($scope === 'meine') { $filterUid = $myId; }

        $dql = "SELECT b FROM App\Entity\FresUserlicences b WHERE b.clientid = :cid "
             . "AND (b.status <> 'geloescht' OR b.status IS NULL)";
        if ($filterUid !== null) {
            $dql .= ' AND b.accountid = :uid';
        }
        $dql .= ' ORDER BY b.accountid ASC, b.validuntil ASC';
        $query = $em->createQuery($dql)->setParameter('cid', $clientid);
        if ($filterUid !== null) {
            $query->setParameter('uid', $filterUid);
        }

        // Farbkodierung wie im alten Formular: >12 Monate = gruen, 3-12 Monate = gelb,
        // <3 Monate oder abgelaufen = rot; unbegrenzt = gruen.
        $today = new \DateTime('today', new \DateTimeZone('Europe/Berlin'));
        $green = (clone $today)->modify('+12 months');
        $amber = (clone $today)->modify('+3 months');
        $kpi = ['green' => 0, 'amber' => 0, 'red' => 0, 'inf' => 0];
        $byUser = [];
        foreach ($query->getResult() as $l) {
            $unlimited = (bool) $l->getValidunlimited();
            $vu = $unlimited ? null : $l->getValiduntil();
            if ($unlimited)        { $state = 'inf'; }
            elseif ($vu > $green)  { $state = 'green'; }
            elseif ($vu > $amber)  { $state = 'amber'; }
            else                   { $state = 'red'; }

            $expired = (!$unlimited && $vu < $today);
            if ($scope === 'abgelaufen' && !$expired) {
                continue;
            }
            ++$kpi[$state];

            $vuStr = $unlimited ? null : $vu->format('d.m.Y');
            $validity = $unlimited
                ? 'unbegrenzt gültig'
                : ($expired ? 'abgelaufen am ' . $vuStr : 'gültig bis ' . $vuStr);
            $badge = $unlimited ? 'unbegrenzt' : ($expired ? 'abgelaufen' : ($state === 'red' ? 'läuft bald ab' : 'gültig'));
            $sortKey = $unlimited ? PHP_INT_MAX : $vu->getTimestamp();   // unbegrenzt ans Ende

            $type = $l->getLicence();
            $cat  = $type ? trim((string) $type->getCategoryname()) : '';
            $long = $type ? trim((string) $type->getLongname()) : '';

            $uid = (int) $l->getAccountid();
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = ['user' => Users::GetUserName($em, $clientid, $uid), 'items' => [], 'amber' => 0, 'red' => 0, 'minkey' => PHP_INT_MAX];
            }
            $byUser[$uid]['items'][] = [
                'id'       => $l->getId(),
                'name'     => $cat !== '' ? $cat : ($long !== '' ? $long : 'Lizenz'),
                'fullname' => ($cat !== '' && $long !== '' && $long !== $cat) ? $long : '',
                'state'    => $state,
                'badge'    => $badge,
                'validity' => $validity,
                'comment'  => trim((string) $l->getComment()),
                'sortkey'  => $sortKey,
            ];
            $byUser[$uid]['minkey'] = min($byUser[$uid]['minkey'], $sortKey);
            if ($state === 'amber') { ++$byUser[$uid]['amber']; }
            if ($state === 'red')   { ++$byUser[$uid]['red']; }
        }

        // Nach Ablaufdatum sortieren: je Nutzer kuerzeste zuerst, Gruppen nach dringendster Lizenz
        $groups = array_values($byUser);
        foreach ($groups as &$g) {
            usort($g['items'], fn (array $a, array $b) => $a['sortkey'] <=> $b['sortkey']);
        }
        unset($g);
        usort($groups, fn (array $a, array $b) => $a['minkey'] <=> $b['minkey']);

        $response = $this->render('modern/licences.html.twig', [
            'groups'     => $groups,
            'kpi'        => $kpi,
            'scope'      => $scope,
            'isAdmin'    => $isAdmin,
            'userFilter' => $userFilter,
            'userName'   => $userFilter ? Users::GetUserName($em, $clientid, $userFilter) : null,
        ]);
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
    public function reserve(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user, int $id = 0): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $tz = new \DateTimeZone('Europe/Berlin');

        // Bearbeiten-Modus: bestehende Buchung laden + Rechte pruefen (gleiche
        // Logik wie die API/das klassische Frontend: IsAllowedtoChangeBooking).
        $edit = null;
        if ($id > 0) {
            $b = Bookings::GetBookingObject($em, $clientid, $id);
            if (!$b) {
                throw $this->createNotFoundException('Buchung nicht gefunden.');
            }
            if (!Bookings::IsAllowedtoChangeBooking($em, $loggedin_user, $b)) {
                throw $this->createAccessDeniedException();
            }
            // Vergangene Buchungen (Ende mehr als eine Woche her) nicht mehr bearbeitbar.
            if (!Bookings::IsBookingDateEditable($b)) {
                throw $this->createAccessDeniedException('Buchungen, deren Ende mehr als eine Woche zurückliegt, können nicht mehr bearbeitet werden.');
            }
            $edit = [
                'id'               => $id,
                'aircraftId'       => (int) $b->getAircraftid(),
                'flightinstructor' => $b->getFlightinstructor() ? (int) $b->getFlightinstructor() : 0,
                'flightpurposeId'  => (int) $b->getFlightpurposeid(),
                'airfieldId'       => (int) $b->getAirfieldid(),
                'date'             => $b->getItemstart()->format('Y-m-d'),
                'endDate'          => $b->getItemstop()->format('Y-m-d'),
                'startTime'        => $b->getItemstart()->format('H:i'),
                'endTime'          => $b->getItemstop()->format('H:i'),
                'description'      => (string) $b->getDescription(),
            ];
        }

        // Vorbelegung aus der Tagesansicht (Flugzeug/Datum), falls vorhanden
        $preAircraft = $edit ? $edit['aircraftId'] : (int) $request->query->get('plane', 0);
        $preDate = \DateTime::createFromFormat('Y-m-d', (string) $request->query->get('date', ''), $tz);
        $today = $edit ? $edit['date'] : ($preDate ?: new \DateTime('today', $tz))->format('Y-m-d');

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
            'editId'      => $id,
            'edit'        => $edit,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /**
     * Lizenz-Formular (Neu) – Piloten fuer sich selbst, Admins fuer jeden Nutzer.
     */
    public function licenceNew(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        // Admins koennen per ?user={id} einen Nutzer vorbelegen (Link aus der Nutzerverwaltung)
        $pre = $this->isGranted('ROLE_ADMIN') ? (int) $request->query->get('user', 0) : 0;

        $values = [
            'id'             => 0,
            'accountid'      => $pre ?: (int) $loggedin_user->getId(),
            'licenceid'      => 0,
            'validunlimited' => false,
            'validuntil'     => '',
            'comment'        => '',
        ];

        return $this->renderLicenceForm($em, $loggedin_user, $values, []);
    }

    /**
     * Lizenz-Formular (Bearbeiten). Piloten nur eigene, Admins jede Lizenz.
     */
    public function licenceEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $ul = Licenses::GetUserLicenceObject($em, $clientid, $id);
        if (!$ul) {
            throw $this->createNotFoundException('Lizenz nicht gefunden.');
        }
        if (!$this->isGranted('ROLE_ADMIN') && (int) $ul->getAccountid() !== (int) $loggedin_user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $unlimited = (bool) $ul->getValidunlimited();
        // Sentinel-Datum (01.01.0000) wieder als "leer" behandeln
        $vu = LicenceController::ChangeValidUntil_Null($ul->getValiduntil());

        $values = [
            'id'             => $ul->getId(),
            'accountid'      => (int) $ul->getAccountid(),
            'licenceid'      => (int) $ul->getLicenceid(),
            'validunlimited' => $unlimited,
            'validuntil'     => (!$unlimited && $vu) ? $vu->format('Y-m-d') : '',
            'comment'        => (string) $ul->getComment(),
        ];

        return $this->renderLicenceForm($em, $loggedin_user, $values, []);
    }

    /**
     * Speichern (Neu/Bearbeiten). Bildet die Validierung und den Persist-/Mail-
     * Ablauf der klassischen LicenceController::SaveAction nach, nutzt also die
     * gleiche geteilte Entity-/Lizenz-Logik (kein zweiter Datenpfad).
     */
    public function licenceSave(MailerInterface $mailer, Environment $twig, Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');

        $id             = (int) $request->request->get('id', 0);
        $licenceid      = (int) $request->request->get('licenceid', 0);
        $validunlimited = (bool) $request->request->get('validunlimited', false);
        $validuntilRaw  = trim((string) $request->request->get('validuntil', ''));
        $comment        = trim((string) $request->request->get('comment', ''));
        $wahrheit       = (bool) $request->request->get('wahrheitsgemaess', false);
        $accountidPost  = (int) $request->request->get('accountid', $myId);

        // --- Lizenz-Objekt laden (Edit) oder neu anlegen ---
        if ($id !== 0) {
            $ul = Licenses::GetUserLicenceObject($em, $clientid, $id);
            if (!$ul) {
                throw $this->createNotFoundException('Lizenz nicht gefunden.');
            }
            if (!$isAdmin && (int) $ul->getAccountid() !== $myId) {
                throw $this->createAccessDeniedException();
            }
            $ul_old = clone $ul;
        } else {
            $ul = new FresUserlicences();
            $ul->setClientid($clientid);
            $ul->setStatus(0);   // nicht geloescht
            $ul_old = null;
        }

        // accountid: Admins frei waehlbar, sonst immer der eigene Nutzer.
        // Bei Bearbeitung bleibt der Eigentuemer fuer Nicht-Admins unveraendert.
        if ($isAdmin) {
            $accountid = $accountidPost ?: $myId;
        } else {
            $accountid = $id !== 0 ? (int) $ul->getAccountid() : $myId;
        }

        // --- Gueltig-bis parsen ---
        $vuDate = false;
        if ($validuntilRaw !== '') {
            $parsed = \DateTime::createFromFormat('Y-m-d', $validuntilRaw);
            if ($parsed instanceof \DateTime) {
                $parsed->setTime(0, 0, 0);
                $vuDate = $parsed;
            }
        }

        $ul->setAccountid($accountid);
        $ul->setLicenceid($licenceid);
        $ul->setValidunlimited($validunlimited ? 1 : 0);
        $ul->setValiduntil($vuDate ?: null);
        $ul->setComment($comment);

        // --- Validierung (wie SaveAction) ---
        $errors = [];
        if (!$validunlimited && !ViewHelper::IsDateCorrect($vuDate ?: false)) {
            $errors[] = 'Bitte geben Sie eine Gültigkeit der Lizenz ein.';
        }
        if (!$wahrheit) {
            $errors[] = 'Bitte bestätigen Sie, dass alle Angaben wahrheitsgemäß erfolgt sind.';
        }
        if ($licenceid <= 0) {
            $errors[] = 'Bitte wählen Sie einen Lizenztyp.';
        } elseif (Licenses::LicenceTypeExistsForUser($em, $ul->getId(), $accountid, $licenceid)) {
            $errors[] = 'Dieser Lizenztyp existiert bereits in der Lizenzliste. Bitte ändern Sie den vorhandenen Eintrag.';
        }

        if ($errors) {
            $values = [
                'id'             => $id,
                'accountid'      => $accountid,
                'licenceid'      => $licenceid,
                'validunlimited' => $validunlimited,
                'validuntil'     => $validuntilRaw,
                'comment'        => $comment,
            ];

            return $this->renderLicenceForm($em, $loggedin_user, $values, $errors);
        }

        // --- Speichern ---
        if ($validunlimited) {
            $ul->setValiduntil(LicenceController::ChangeValidUntil_NotNull());
        }
        // One-to-One-Beziehungen muessen gesetzt sein (sonst kein Persist)
        $ul->setUser(Users::GetUserObject($em, $clientid, $accountid));
        $ul->setLicence(Licenses::GetLicenceTypeObject($em, $licenceid));

        $em->persist($ul);
        $em->flush();

        // --- Info-Mail (darf den Speichervorgang nicht abbrechen) ---
        $parameter = [
            'program_version' => $this->getParameter('program_version'),
            'mail_from'       => $this->getParameter('mail_from'),
        ];
        try {
            Licenses::SendLicenceInfoMail($em, $loggedin_user, $twig, $ul, $ul_old, $mailer, $parameter);
        } catch (\Throwable $e) {
            // Mailversand fehlgeschlagen – Lizenz ist trotzdem gespeichert.
        }

        return $this->redirectToRoute('modern_licences', $accountid !== $myId ? ['scope' => 'alle'] : []);
    }

    /**
     * Rendert das Lizenz-Formular (Neu + Bearbeiten + Re-Render bei Fehlern).
     */
    private function renderLicenceForm(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $clientid = $loggedin_user->getClientid();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');

        // Lizenztypen inkl. Beschreibung (fuer die Live-Vorschau im Formular)
        $typeRows = $em->createQuery(
            "SELECT b FROM App\Entity\FresLicencetype b ORDER BY b.categoryid ASC, b.longname DESC"
        )->getResult();
        $types = [];
        foreach ($typeRows as $t) {
            $types[] = [
                'id'    => $t->getId(),
                'label' => trim($t->getCategoryname() . ' ' . $t->getLongname()),
                'desc'  => trim((string) $t->getDescription()),
            ];
        }

        $response = $this->render('modern/licence_form.html.twig', [
            'isNew'    => ((int) ($values['id'] ?? 0)) === 0,
            'isAdmin'  => $isAdmin,
            'types'    => $types,
            'users'    => $isAdmin ? Users::GetAllUsersForListbox($em, $clientid) : [],
            'userName' => Users::GetUserName($em, $clientid, (int) $values['accountid']),
            'today'    => (new \DateTime('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            'v'        => $values,
            'errors'   => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /**
     * Pinnwand (Notes) – Karten-Ansicht der aktiven Eintraege. Nutzt die
     * bestehende Datenlogik (Notes::GetAllActiveNotesAsObject). Bearbeiten/
     * Loeschen nur fuer den Ersteller bzw. ROLE_SYSTEM_ADMIN.
     */
    public function notes(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid  = $loggedin_user->getClientid();
        $myId      = (int) $loggedin_user->getId();
        $isSysAdmin = $this->isGranted('ROLE_SYSTEM_ADMIN');

        $scope = (string) $request->query->get('scope', 'aktuell');
        if (!in_array($scope, ['aktuell', 'meine'], true)) { $scope = 'aktuell'; }

        $today = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        $items = [];
        foreach (Notes::GetAllActiveNotesAsObject($em) as $n) {
            if ((int) $n->getClientid() !== (int) $clientid) { continue; }   // nur eigener Mandant
            $owner = (int) $n->getCreatedbyuserid();
            if ($scope === 'meine' && $owner !== $myId) { continue; }

            $u   = $n->getUser();
            $vu  = $n->getValiduntil();
            $days = $vu ? (int) $today->diff($vu)->format('%r%a') : null;
            $items[] = [
                'id'        => $n->getId(),
                'header'    => $n->getHeader(),
                'text'      => nl2br((string) $n->getDescription()),
                'author'    => $u ? trim($u->getFirstname() . ' ' . $u->getLastname()) : '',
                'validuntil'=> $vu ? $vu->format('d.m.Y') : '',
                'days'      => $days,
                'soon'      => $days !== null && $days <= 3,
                'created'   => $n->getCreateddate() ? $n->getCreateddate()->format('d.m.Y') : '',
                'canedit'   => $isSysAdmin || $owner === $myId,
            ];
        }
        // Dringlichste (kleinste Restlaufzeit) zuerst
        usort($items, fn (array $a, array $b) => ($a['days'] ?? PHP_INT_MAX) <=> ($b['days'] ?? PHP_INT_MAX));

        $response = $this->render('modern/notes.html.twig', [
            'items' => $items,
            'scope' => $scope,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Pinnwand-Formular (Neu). */
    public function noteNew(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $tz = new \DateTimeZone('Europe/Berlin');

        // Standard-Gueltigkeit: einen Monat (Maximum fuer normale Nutzer)
        $default = (new \DateTime('today', $tz))->modify('+1 month')->format('Y-m-d');
        $values = ['id' => 0, 'header' => '', 'description' => '', 'validuntil' => $default];

        return $this->renderNoteForm($em, $loggedin_user, $values, []);
    }

    /** Pinnwand-Formular (Bearbeiten). */
    public function noteEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $note = Notes::GetNoteObject($em, $loggedin_user->getClientid(), $id);
        if (!$note || $note->getStatus() === Notes::const_geloescht) {
            throw $this->createNotFoundException('Pinnwandeintrag nicht gefunden.');
        }
        if (!$this->isGranted('ROLE_SYSTEM_ADMIN') && (int) $note->getCreatedbyuserid() !== (int) $loggedin_user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $vu = $note->getValiduntil();
        $values = [
            'id'          => $note->getId(),
            'header'      => (string) $note->getHeader(),
            'description' => (string) $note->getDescription(),
            'validuntil'  => $vu ? $vu->format('Y-m-d') : '',
        ];

        return $this->renderNoteForm($em, $loggedin_user, $values, []);
    }

    /** Pinnwand speichern (Neu/Bearbeiten) – bildet NoteController::SaveAction nach. */
    public function noteSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid   = $loggedin_user->getClientid();
        $myId       = (int) $loggedin_user->getId();
        $isSysAdmin = $this->isGranted('ROLE_SYSTEM_ADMIN');
        $tz = new \DateTimeZone('Europe/Berlin');

        $id          = (int) $request->request->get('id', 0);
        $header      = trim((string) $request->request->get('header', ''));
        $description = (string) $request->request->get('description', '');
        $vuRaw       = trim((string) $request->request->get('validuntil', ''));

        if ($id !== 0) {
            $note = Notes::GetNoteObject($em, $clientid, $id);
            if (!$note || $note->getStatus() === Notes::const_geloescht) {
                throw $this->createNotFoundException('Pinnwandeintrag nicht gefunden.');
            }
            if (!$isSysAdmin && (int) $note->getCreatedbyuserid() !== $myId) {
                throw $this->createAccessDeniedException();
            }
            $note->setChangedbyuserid($myId);
        } else {
            $note = new FresNote();
            $note->setCreateddate(new \DateTime('now', $tz));
            $note->setCreatedbyuserid($myId);
            $note->setStatus(0);
        }
        $note->setChangeddate(new \DateTime('now', $tz));
        $note->setClientid($clientid);
        $note->setHeader($header);
        $note->setDescription($description);

        // Gueltig-bis parsen
        $vuDate = false;
        if ($vuRaw !== '') {
            $parsed = \DateTime::createFromFormat('Y-m-d', $vuRaw, $tz);
            if ($parsed instanceof \DateTime) { $parsed->setTime(23, 59, 0); $vuDate = $parsed; }
        }
        $note->setValiduntil($vuDate ?: null);

        // Validierung (wie SaveAction)
        $errors = [];
        if ($header === '') { $errors[] = 'Bitte geben Sie einen Titel ein.'; }
        if (trim($description) === '') { $errors[] = 'Bitte geben Sie einen Text ein.'; }
        if (!$vuDate) {
            $errors[] = 'Das „Gültig bis"-Datum ist kein gültiges Datum.';
        } elseif (!$isSysAdmin) {
            $maxDate = (new \DateTime('now', $tz))->modify('+1 month');
            if ($vuDate > $maxDate) {
                $errors[] = 'Das „Gültig bis"-Datum darf maximal einen Monat in der Zukunft liegen.';
            }
        }

        if ($errors) {
            $values = ['id' => $id, 'header' => $header, 'description' => $description, 'validuntil' => $vuRaw];
            return $this->renderNoteForm($em, $loggedin_user, $values, $errors);
        }

        if ($id === 0) {
            // One-to-One-Beziehung muss gesetzt sein (sonst kein Persist)
            $note->setUser(Users::GetUserObject($em, $clientid, $myId));
        }
        $em->persist($note);
        $em->flush();

        return $this->redirectToRoute('modern_notes');
    }

    /** Pinnwand-Eintrag (soft) loeschen – nur Ersteller bzw. ROLE_SYSTEM_ADMIN. */
    public function noteDelete(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $id   = (int) $request->request->get('id', 0);
        $note = $id ? Notes::GetNoteObject($em, $clientid, $id) : null;
        if (!$note || $note->getStatus() === Notes::const_geloescht) {
            throw $this->createNotFoundException('Pinnwandeintrag nicht gefunden.');
        }
        if (!$this->isGranted('ROLE_SYSTEM_ADMIN') && (int) $note->getCreatedbyuserid() !== (int) $loggedin_user->getId()) {
            throw $this->createAccessDeniedException();
        }

        Notes::DeleteNote($em, $clientid, $id);

        return $this->redirectToRoute('modern_notes');
    }

    /** Rendert das Pinnwand-Formular (Neu + Bearbeiten + Re-Render bei Fehlern). */
    private function renderNoteForm(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $response = $this->render('modern/note_form.html.twig', [
            'isNew'      => ((int) ($values['id'] ?? 0)) === 0,
            'isSysAdmin' => $this->isGranted('ROLE_SYSTEM_ADMIN'),
            'today'      => (new \DateTime('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            'v'          => $values,
            'errors'     => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    // ====================================================================
    //  Stammdaten: Flugzeuge & Flugzeugtypen (nur ROLE_SYSTEM_ADMIN, wie
    //  im klassischen Menue). Nutzt die bestehende Planes-/Licenses-Logik.
    // ====================================================================

    /** Liste (Tabs: Flugzeuge / Flugzeugtypen). */
    public function aircraft(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $tab = (string) $request->query->get('tab', 'flugzeuge');
        if (!in_array($tab, ['flugzeuge', 'typen'], true)) { $tab = 'flugzeuge'; }

        // Typ-Label-Map (id => "Langname (Kurz)") fuer die Flugzeugliste
        $typeMap = array_flip(Planes::GetAllAircraftTypes($em, $clientid));

        $planes = [];
        $pq = $em->createQuery("SELECT b FROM App\Entity\FresAircraft b WHERE b.clientid = :cid AND b.status <> 'geloescht' ORDER BY b.aircraft ASC")
                 ->setParameter('cid', $clientid)->getResult();
        foreach ($pq as $p) {
            $planes[] = [
                'id'      => $p->getId(),
                'kennung' => (string) $p->getKennung(),
                'name'    => (string) $p->getAircraft(),
                'type'    => $typeMap[$p->getAircrafttype()] ?? '—',
                'advance' => $p->getAdvancebooking(),
                'active'  => ((string) $p->getStatus() !== Planes::const_inactive),
            ];
        }

        $types = [];
        $tq = $em->createQuery("SELECT b FROM App\Entity\FresAircrafttype b WHERE b.clientid = :cid AND b.status <> 'geloescht' ORDER BY b.longname ASC")
                 ->setParameter('cid', $clientid)->getResult();
        foreach ($tq as $t) {
            $lics = [];
            foreach ($t->getLicencetypes() as $lt) {
                $cat = trim((string) $lt->getCategoryname());
                $lics[] = $cat !== '' ? $cat : trim((string) $lt->getLongname());
            }
            $types[] = [
                'id'       => $t->getId(),
                'short'    => (string) $t->getShortname(),
                'long'     => (string) $t->getLongname(),
                'licences' => $lics,
            ];
        }

        $response = $this->render('modern/aircraft.html.twig', [
            'tab'    => $tab,
            'planes' => $planes,
            'types'  => $types,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Flugzeug-Formular (Neu). */
    public function aircraftNew(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $values = ['id' => 0, 'kennung' => '', 'name' => '', 'aircrafttype' => 0, 'advance' => '', 'active' => true];

        return $this->renderAircraftForm($em, $loggedin_user, $values, []);
    }

    /** Flugzeug-Formular (Bearbeiten). */
    public function aircraftEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $p = Planes::GetPlaneObject($em, $loggedin_user->getClientid(), $id, true);
        if (!$p) {
            throw $this->createNotFoundException('Flugzeug nicht gefunden.');
        }
        $values = [
            'id'           => $p->getId(),
            'kennung'      => (string) $p->getKennung(),
            'name'         => (string) $p->getAircraft(),
            'aircrafttype' => (int) $p->getAircrafttype(),
            'advance'      => $p->getAdvancebooking(),
            'active'       => ((string) $p->getStatus() !== Planes::const_inactive),
        ];

        return $this->renderAircraftForm($em, $loggedin_user, $values, []);
    }

    /** Flugzeug speichern (Neu/Bearbeiten). */
    public function aircraftSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $id         = (int) $request->request->get('id', 0);
        $kennung    = trim((string) $request->request->get('kennung', ''));
        $name       = trim((string) $request->request->get('name', ''));
        $type       = (int) $request->request->get('aircrafttype', 0);
        $advanceRaw = trim((string) $request->request->get('advance', ''));
        $active     = (bool) $request->request->get('active', false);

        if ($id !== 0) {
            $p = Planes::GetPlaneObject($em, $clientid, $id, true);
            if (!$p) {
                throw $this->createNotFoundException('Flugzeug nicht gefunden.');
            }
        } else {
            $p = new FresAircraft();
            $p->setClientid($clientid);
        }

        $errors = [];
        if ($kennung === '') { $errors[] = 'Bitte eine Kennung eingeben.'; }
        if ($name === '')    { $errors[] = 'Bitte einen Namen eingeben.'; }
        if ($type <= 0)      { $errors[] = 'Bitte einen Flugzeugtyp wählen.'; }

        if ($errors) {
            $values = ['id' => $id, 'kennung' => $kennung, 'name' => $name, 'aircrafttype' => $type, 'advance' => $advanceRaw, 'active' => $active];
            return $this->renderAircraftForm($em, $loggedin_user, $values, $errors);
        }

        $p->setKennung($kennung);
        $p->setAircraft($name);
        $p->setAircrafttype($type);
        $p->setAdvancebooking($advanceRaw === '' ? 0 : (int) $advanceRaw);
        $p->setStatus($active ? 0 : Planes::const_inactive);
        $p->setAdminids(null);   // wie klassisch: keine Flugzeug-Administratoren

        $em->persist($p);
        $em->flush();

        return $this->redirectToRoute('modern_aircraft');
    }

    private function renderAircraftForm(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $response = $this->render('modern/aircraft_form.html.twig', [
            'isNew'  => ((int) ($values['id'] ?? 0)) === 0,
            'types'  => Planes::GetAllAircraftTypes($em, $loggedin_user->getClientid()),
            'v'      => $values,
            'errors' => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Flugzeugtyp-Formular (Neu). */
    public function aircraftTypeNew(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $values = ['id' => 0, 'short' => '', 'long' => '', 'licences' => []];

        return $this->renderTypeForm($em, $loggedin_user, $values, []);
    }

    /** Flugzeugtyp-Formular (Bearbeiten). */
    public function aircraftTypeEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $t = $this->findAircraftType($em, $loggedin_user->getClientid(), $id);
        if (!$t) {
            throw $this->createNotFoundException('Flugzeugtyp nicht gefunden.');
        }
        $sel = [];
        foreach ($t->getLicencetypes() as $lt) { $sel[] = (int) $lt->getId(); }
        $values = ['id' => $t->getId(), 'short' => (string) $t->getShortname(), 'long' => (string) $t->getLongname(), 'licences' => $sel];

        return $this->renderTypeForm($em, $loggedin_user, $values, []);
    }

    /** Flugzeugtyp speichern (Neu/Bearbeiten). */
    public function aircraftTypeSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $id    = (int) $request->request->get('id', 0);
        $short = trim((string) $request->request->get('short', ''));
        $long  = trim((string) $request->request->get('long', ''));
        $sel   = array_map('intval', (array) $request->request->all('licencetypes'));

        if ($id !== 0) {
            $t = $this->findAircraftType($em, $clientid, $id);
            if (!$t) {
                throw $this->createNotFoundException('Flugzeugtyp nicht gefunden.');
            }
        } else {
            $t = new FresAircrafttype();
            $t->setClientid($clientid);
            $t->setStatus(0);
        }

        $errors = [];
        if ($short === '') { $errors[] = 'Bitte einen Kurznamen eingeben.'; }
        if ($long === '')  { $errors[] = 'Bitte einen Langnamen eingeben.'; }

        if ($errors) {
            $values = ['id' => $id, 'short' => $short, 'long' => $long, 'licences' => $sel];
            return $this->renderTypeForm($em, $loggedin_user, $values, $errors);
        }

        $t->setShortname($short);
        $t->setLongname($long);
        // Lizenztyp-Zuordnung (ManyToMany, Owning Side) neu setzen
        $t->getLicencetypes()->clear();
        foreach ($sel as $lid) {
            $lt = $em->getRepository('App\Entity\FresLicencetype')->find($lid);
            if ($lt) { $t->getLicencetypes()->add($lt); }
        }

        $em->persist($t);
        $em->flush();

        return $this->redirectToRoute('modern_aircraft', ['tab' => 'typen']);
    }

    /** Flugzeugtyp (soft) loeschen. */
    public function aircraftTypeDelete(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $id = (int) $request->request->get('id', 0);
        if ($id && $this->findAircraftType($em, $clientid, $id)) {
            Licenses::DeleteAircraftType($em, $clientid, $id);
        }

        return $this->redirectToRoute('modern_aircraft', ['tab' => 'typen']);
    }

    private function renderTypeForm(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $licenceTypes = [];
        $lq = $em->createQuery("SELECT b FROM App\Entity\FresLicencetype b ORDER BY b.categoryid ASC, b.longname ASC")->getResult();
        foreach ($lq as $lt) {
            $licenceTypes[] = ['id' => (int) $lt->getId(), 'label' => trim($lt->getCategoryname() . ' ' . $lt->getLongname())];
        }

        $response = $this->render('modern/actype_form.html.twig', [
            'isNew'        => ((int) ($values['id'] ?? 0)) === 0,
            'licenceTypes' => $licenceTypes,
            'v'            => $values,
            'errors'       => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Laedt einen nicht-geloeschten Flugzeugtyp des Mandanten (oder null). */
    private function findAircraftType(EntityManagerInterface $em, $clientid, int $id): ?FresAircrafttype
    {
        $t = $em->getRepository('App\Entity\FresAircrafttype')->findOneBy(['clientid' => $clientid, 'id' => $id]);
        if (!$t || (string) $t->getStatus() === Planes::const_geloescht) {
            return null;
        }

        return $t;
    }

    // ====================================================================
    //  Verwaltung: Nutzer (nur ROLE_SYSTEM_ADMIN, wie im klassischen Menue).
    //  Nutzt die bestehende Users-/Functions-Logik (Rollen, Passwort, FI).
    // ====================================================================

    /** Nutzerliste (Karten). */
    public function users(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $items = [];
        foreach (Users::GetAllUsers($em, $clientid) as $u) {
            $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            $roleStrings = Functions::GetAllRolesForUserId($em, (int) $u['id']);
            $phone = trim(implode(' ', array_filter([
                $u['phoneNumberHome'] ?? '', $u['phoneNumberOffice'] ?? '', $u['phoneNumberMobile'] ?? '',
            ])));
            $items[] = [
                'id'       => $u['id'],
                'name'     => $name !== '' ? $name : ($u['username'] ?? ''),
                'username' => $u['username'] ?? '',
                'email'    => $u['email'] ?? '',
                'phone'    => $phone,
                'roles'    => array_values((array) ($u['function'] ?? [])),
                'locked'   => (bool) ($u['islocked'] ?? false),
                'isFi'     => in_array('ROLE_FI', $roleStrings, true),
                'isAdmin'  => (bool) array_intersect(['ROLE_ADMIN', 'ROLE_SYSTEM_ADMIN', 'ROLE_GLOBAL_ADMIN'], $roleStrings),
            ];
        }

        $response = $this->render('modern/users.html.twig', ['items' => $items]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Nutzer-Formular (Neu). */
    public function userNew(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $values = [
            'id' => 0, 'firstname' => '', 'lastname' => '', 'username' => '', 'email' => '',
            'phone_home' => '', 'phone_office' => '', 'phone_mobile' => '',
            'getbookingmails' => 1, 'getlicencemails' => 1, 'islocked' => false,
            'roles' => [], 'isAdmin' => false, 'isFi' => false,
            'fiallwaysavailable' => 0, 'fiparallelbookings' => false, 'fibookableifonrequest' => false,
        ];

        return $this->renderUserForm($em, $loggedin_user, $values, []);
    }

    /** Nutzer-Formular (Bearbeiten). */
    public function userEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $u = Users::GetUserObject($em, $loggedin_user->getClientid(), $id);
        if (!$u || (string) $u->getStatus() === 'geloescht') {
            throw $this->createNotFoundException('Nutzer nicht gefunden.');
        }

        $values = $this->userToValues($em, $u);

        return $this->renderUserForm($em, $loggedin_user, $values, []);
    }

    /** Nutzer speichern (Neu/Bearbeiten) – bildet EditUserController::SaveAction nach. */
    public function userSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user, UserPasswordHasherInterface $hasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $id        = (int) $request->request->get('id', 0);
        $firstname = trim((string) $request->request->get('firstname', ''));
        $lastname  = trim((string) $request->request->get('lastname', ''));
        $username  = trim((string) $request->request->get('username', ''));
        $email     = trim((string) $request->request->get('email', ''));
        $phoneH    = trim((string) $request->request->get('phone_home', ''));
        $phoneO    = trim((string) $request->request->get('phone_office', ''));
        $phoneM    = trim((string) $request->request->get('phone_mobile', ''));
        $bookmail  = (int) $request->request->get('getbookingmails', 0);
        $licmail   = (int) $request->request->get('getlicencemails', 0);
        $islocked  = (bool) $request->request->get('islocked', false);
        $pass      = trim((string) $request->request->get('password', ''));
        $pass2     = trim((string) $request->request->get('password_check', ''));
        $hasFiSec  = (bool) $request->request->get('fi_section', false);
        $selRoles  = array_map('intval', (array) $request->request->all('functions'));

        if ($id !== 0) {
            $u = Users::GetUserObject($em, $clientid, $id);
            if (!$u || (string) $u->getStatus() === 'geloescht') {
                throw $this->createNotFoundException('Nutzer nicht gefunden.');
            }
        } else {
            $u = new FresAccounts();
            $u->setClientid($clientid);
            $u->setStatus(0);
        }

        // --- Validierung (wie SaveAction) ---
        $errors = [];
        if ($firstname === '') { $errors[] = 'Bitte einen Vornamen eingeben.'; }
        if ($lastname === '')  { $errors[] = 'Bitte einen Nachnamen eingeben.'; }
        if ($username === '')   { $errors[] = 'Bitte einen Nutzernamen eingeben.'; }
        if ($id === 0 && $username !== '' && Users::GetUserObjectByName($em, $username, $clientid) !== null) {
            $errors[] = 'Diesen Nutzernamen gibt es bereits.';
        }
        if ($email === '' || !Users::IsMailListValid($email)) {
            $errors[] = 'Die Mailadresse ist nicht gültig.';
        }
        if ($id === 0 && $pass === '') {
            $errors[] = 'Bitte ein Passwort vergeben.';
        }
        if ($pass !== '') {
            if ($pass !== $pass2) { $errors[] = 'Die Passwörter sind nicht identisch.'; }
            elseif (strlen($pass) < 5) { $errors[] = 'Das Passwort muss mindestens 5 Zeichen lang sein.'; }
        }

        if ($errors) {
            $values = [
                'id' => $id, 'firstname' => $firstname, 'lastname' => $lastname, 'username' => $username, 'email' => $email,
                'phone_home' => $phoneH, 'phone_office' => $phoneO, 'phone_mobile' => $phoneM,
                'getbookingmails' => $bookmail, 'getlicencemails' => $licmail, 'islocked' => $islocked,
                'roles' => $selRoles,
                'isAdmin' => $id !== 0 ? Users::isAdmin($em, $u) : false,
                'isFi'    => $hasFiSec || ($id !== 0 && Users::isFlightinstructor($em, $u->getId())),
                'fiallwaysavailable'    => (int) $request->request->get('fiallwaysavailable', 0),
                'fiparallelbookings'    => (bool) $request->request->get('fiparallelbookings', false),
                'fibookableifonrequest' => (bool) $request->request->get('fibookableifonrequest', false),
            ];
            return $this->renderUserForm($em, $loggedin_user, $values, $errors);
        }

        // --- Speichern ---
        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $u->setUsername($username);
        $u->setEmail($email);
        $u->setPhoneNumberHome($phoneH);
        $u->setPhoneNumberOffice($phoneO);
        $u->setPhoneNumberMobile($phoneM);
        $u->setGetbookingmails($bookmail);
        $u->setGetlicencemails($licmail);
        $u->setIslocked($islocked);
        if ($pass !== '') {
            $u->setPassword(Users::CreateNewPassword($loggedin_user, $hasher, $pass));
        }

        // Rollen (FRes_user2Functions): nur Funktionen unterhalb der eigenen
        // Berechtigungsgrenze sind aenderbar; hoehere Rollen bleiben erhalten.
        $limit = $this->isGranted('ROLE_GLOBAL_ADMIN') ? 7 : 6;
        $keep = [];
        foreach ($u->getFunction() as $f) {
            if ((int) $f->getPriority() >= $limit) { $keep[(int) $f->getId()] = $f; }
        }
        $u->getFunction()->clear();
        foreach ($keep as $f) { $u->getFunction()->add($f); }
        if ($selRoles) {
            $allowed = $em->createQuery('SELECT b FROM App\Entity\FresFunction b WHERE b.id IN (:ids) AND b.priority < :lim')
                          ->setParameter('ids', $selRoles)->setParameter('lim', $limit)->getResult();
            foreach ($allowed as $f) {
                if (!$u->getFunction()->contains($f)) { $u->getFunction()->add($f); }
            }
        }

        // FI-Flags nur uebernehmen, wenn der FI-Abschnitt angezeigt wurde
        if ($hasFiSec) {
            $u->setFiallwaysavailable((int) $request->request->get('fiallwaysavailable', 0));
            $u->setFiparallelbookings((bool) $request->request->get('fiparallelbookings', false));
            $u->setFibookableifonrequest((bool) $request->request->get('fibookableifonrequest', false));
        }

        $em->persist($u);
        $em->flush();

        return $this->redirectToRoute('modern_users');
    }

    /** Nutzer loeschen (Soft-Delete inkl. Buchungen – wie Users::DeleteUser). */
    public function userDelete(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');
        $clientid = $loggedin_user->getClientid();

        $id = (int) $request->request->get('id', 0);
        if ($id && $id !== (int) $loggedin_user->getId()) {   // sich selbst nicht loeschen
            $u = Users::GetUserObject($em, $clientid, $id);
            if ($u && (string) $u->getStatus() !== 'geloescht') {
                Users::DeleteUser($em, $clientid, $id);
            }
        }

        return $this->redirectToRoute('modern_users');
    }

    /** Baut das Vorbelegungs-Array aus einem FresAccounts-Objekt. */
    private function userToValues(EntityManagerInterface $em, FresAccounts $u): array
    {
        $roles = [];
        foreach ($u->getFunction() as $f) { $roles[] = (int) $f->getId(); }

        return [
            'id'             => $u->getId(),
            'firstname'      => (string) $u->getFirstname(),
            'lastname'       => (string) $u->getLastname(),
            'username'       => (string) $u->getUsername(),
            'email'          => (string) $u->getEmail(),
            'phone_home'     => (string) $u->getPhoneNumberHome(),
            'phone_office'   => (string) $u->getPhoneNumberOffice(),
            'phone_mobile'   => (string) $u->getPhoneNumberMobile(),
            'getbookingmails'=> (int) $u->getGetbookingmails(),
            'getlicencemails'=> (int) $u->getGetlicencemails(),
            'islocked'       => (bool) $u->getIslocked(),
            'roles'          => $roles,
            'isAdmin'        => Users::isAdmin($em, $u),
            'isFi'           => Users::isFlightinstructor($em, $u->getId()),
            'fiallwaysavailable'    => (int) $u->getFiallwaysavailable(),
            'fiparallelbookings'    => (bool) $u->getFiparallelbookings(),
            'fibookableifonrequest' => (bool) $u->getFibookableifonrequest(),
        ];
    }

    private function renderUserForm(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        // Nur Rollen unterhalb der eigenen Berechtigungsgrenze sind vergebbar.
        $limit = $this->isGranted('ROLE_GLOBAL_ADMIN') ? 7 : 6;
        $functions = [];
        $fq = $em->createQuery('SELECT b FROM App\Entity\FresFunction b WHERE b.priority < :lim ORDER BY b.priority ASC')
                 ->setParameter('lim', $limit)->getResult();
        foreach ($fq as $f) {
            $functions[] = ['id' => (int) $f->getId(), 'name' => $f->getFunction(), 'priority' => (int) $f->getPriority()];
        }

        $response = $this->render('modern/user_form.html.twig', [
            'isNew'     => ((int) ($values['id'] ?? 0)) === 0,
            'functions' => $functions,
            'fiAvail'   => Users::GetFlightinstructorAvailabilities($em, 0),
            'v'         => $values,
            'errors'    => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    // ====================================================================
    //  Verwaltung: Mandanten (nur ROLE_GLOBAL_ADMIN). Nutzt die bestehende
    //  FresClient-Entity wie AdminConfigController, im /modern-Design.
    // ====================================================================

    /** Mandantenliste (Karten + Statistik). */
    public function mandanten(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $items = [];
        foreach ($em->getRepository(FresClient::class)->findBy([], ['name' => 'ASC']) as $c) {
            $bookings = (int) $em->createQuery(
                "SELECT COUNT(b.id) FROM App\Entity\FresBooking b WHERE b.clientid = :cid "
                . "AND b.status <> 'storniert' AND b.status <> 'flugzeug_geloescht' AND b.status <> 'user_geloescht'"
            )->setParameter('cid', $c->getId())->getSingleScalarResult();

            $items[] = [
                'id'       => $c->getId(),
                'name'     => $c->getName(),
                'active'   => $c->isActive(),
                'users'    => (int) $em->getRepository('App\Entity\FresAccounts')->count(['clientid' => $c->getId()]),
                'aircraft' => (int) $em->getRepository('App\Entity\FresAircraft')->count(['clientid' => $c->getId()]),
                'bookings' => $bookings,
            ];
        }

        $response = $this->render('modern/mandanten.html.twig', ['items' => $items]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Mandant-Formular (Neu). */
    public function mandantNew(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        return $this->renderMandantForm(['id' => 0, 'name' => '', 'active' => true], []);
    }

    /** Mandant-Formular (Bearbeiten). */
    public function mandantEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $c = $em->getRepository(FresClient::class)->find($id);
        if (!$c) {
            throw $this->createNotFoundException('Mandant nicht gefunden.');
        }

        return $this->renderMandantForm(['id' => $c->getId(), 'name' => (string) $c->getName(), 'active' => $c->isActive()], []);
    }

    /** Mandant speichern (Neu/Bearbeiten). */
    public function mandantSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $id     = (int) $request->request->get('id', 0);
        $name   = trim((string) $request->request->get('name', ''));
        $active = (bool) $request->request->get('active', false);

        if ($id !== 0) {
            $c = $em->getRepository(FresClient::class)->find($id);
            if (!$c) {
                throw $this->createNotFoundException('Mandant nicht gefunden.');
            }
        } else {
            $c = new FresClient();
        }

        if ($name === '') {
            return $this->renderMandantForm(['id' => $id, 'name' => $name, 'active' => $active], ['Bitte einen Namen eingeben.']);
        }

        $c->setName($name);
        $c->setActive($active);
        $em->persist($c);
        $em->flush();

        return $this->redirectToRoute('modern_mandanten');
    }

    /** Mandant (de)aktivieren. */
    public function mandantToggle(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $em->getConnection()->exec('SET NAMES "UTF8"');

        $id = (int) $request->request->get('id', 0);
        $c  = $id ? $em->getRepository(FresClient::class)->find($id) : null;
        if ($c) {
            $c->setActive(!$c->isActive());
            $em->flush();
        }

        return $this->redirectToRoute('modern_mandanten');
    }

    private function renderMandantForm(array $values, array $errors): Response
    {
        $response = $this->render('modern/mandant_form.html.twig', [
            'isNew'  => ((int) ($values['id'] ?? 0)) === 0,
            'v'      => $values,
            'errors' => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }
}
