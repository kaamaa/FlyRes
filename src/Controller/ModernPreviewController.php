<?php

namespace App\Controller;

use App\Entities\Airfields;
use App\Entities\Bookings;
use App\Tools\HtmlSanitizer;
use App\Entities\FIAvailability;
use App\Entities\FlightPurposes;
use App\Entities\Functions;
use App\Entities\Licenses;
use App\Entities\Licensetype;
use App\Entity\FresLicencetype;
use App\Entities\Notes;
use App\Entities\Clients;
use App\Entities\Planes;
use App\Entities\Users;
use App\TimeFunctions;
use App\Entity\FresAccounts;
use App\Entity\FresAircraft;
use App\Entity\FresAircrafttype;
use App\Entity\FresClient;
use App\Entity\FresFIAvailability;
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
    use MailParamsTrait;

    /** Zeitfenster (DB-Fetch) – wie generalview-Standardansichten + thismonth. */
    private const TIME_COMMANDS = ['date', 'today', 'tomorrow', 'thisweek', 'weekafter', 'thisweekend', 'nextweekend', 'thismonth'];
    private const GROUPINGS     = ['datum', 'flugzeug', 'fluglehrer', 'nutzer'];
    private const ZWECKE        = ['alle', 'charter', 'schulung', 'wartung'];
    private const UMFAENGE      = ['alle', 'meine', 'historie'];
    private const MONTHS_DE     = TimeFunctions::MONTHS;      // zentral in TimeFunctions
    private const WEEKDAYS_DE   = TimeFunctions::WEEKDAYS;    // zentral in TimeFunctions

    /**
     * Persoenliches Dashboard (Login-Startseite). Bringt eigene Buchungen,
     * Lizenz-Status/Warnungen und Pinnwand zusammen; fuer Fluglehrer zusaetzlich
     * die eigenen Schulungstermine, fuer Admins club-weite Lizenz-Warnungen und
     * Kennzahlen. Nutzt die bestehende Datenlogik (Bookings/Notes/Planes/Users).
     */
    public function dashboard(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isFi     = Users::isFlightinstructor($em, $myId);
        $isAdmin  = $this->isGranted('ROLE_ADMIN');

        $tz    = new \DateTimeZone('Europe/Berlin');
        $now   = new \DateTime('now', $tz);
        $today = new \DateTime('today', $tz);
        $green = (clone $today)->modify('+12 months');
        $amber = (clone $today)->modify('+3 months');
        $wd    = TimeFunctions::WEEKDAYS_SHORT;   // 1-indexiert (1=Mo)
        $statusOk = Bookings::ACTIVE_STATUS_DQL;

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
                'tag'      => $wd[(int) $start->format('N')] . ' ' . $start->format('d.m.'),
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

            // --- Flugstunden / Schulungsanteil / Trends / Top-Listen ---
            // Basis: gebuchte Stunden = Summe (itemstop - itemstart) je Buchung.
            // Stornierte/geloeschte ausgeschlossen.
            $conn      = $em->getConnection();
            $statusSql = Bookings::ACTIVE_STATUS_SQL;

            $sumMin = function (\DateTimeInterface $from, \DateTimeInterface $to, string $extra = '') use ($conn, $clientid, $statusSql) {
                return (int) $conn->executeQuery(
                    "SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, itemstart, itemstop)),0) FROM FRes_booking "
                    . "WHERE clientid = :cid AND $statusSql AND itemstart >= :from AND itemstart < :to " . $extra,
                    ['cid' => $clientid, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]
                )->fetchOne();
            };

            // Monat: nur ABGESCHLOSSENE Monate bewerten -> letzter voller Monat vs.
            // Monat davor. Der laufende (angefangene) Monat wird bewusst ignoriert.
            $curMonthStart  = new \DateTime($today->format('Y-m') . '-01 00:00:00', $tz);
            $compStart      = (clone $curMonthStart)->modify('-1 month');   // letzter voller Monat
            $compPrevStart  = (clone $compStart)->modify('-1 month');       // Monat davor
            $minComp     = $sumMin($compStart, $curMonthStart);
            $minCompPrev = $sumMin($compPrevStart, $compStart);
            $stats['hoursMonth']     = (int) round($minComp / 60);
            $stats['trendPct']       = $minCompPrev > 0 ? (int) round(($minComp - $minCompPrev) / $minCompPrev * 100) : null;
            $stats['monthLabel']     = self::MONTHS_DE[(int) $compStart->format('n')] . ' ' . $compStart->format('Y');
            $stats['monthPrevLabel'] = self::MONTHS_DE[(int) $compPrevStart->format('n')];

            // Jahr: Year-to-date (1.1. bis jetzt) vs. Vorjahres-YTD (gleicher Stichtag).
            $yearStart     = new \DateTime($today->format('Y') . '-01-01 00:00:00', $tz);
            $lastYearStart = (clone $yearStart)->modify('-1 year');
            $lastYtdCut    = (clone $now)->modify('-1 year');
            $minYtd     = $sumMin($yearStart, $now);
            $minYtdLast = $sumMin($lastYearStart, $lastYtdCut);
            $minSchul   = $sumMin($yearStart, $now, 'AND flightpurposeid IN (2,5)');   // 2=Schulung m. FI, 5=Solo
            $stats['hoursYear']     = (int) round($minYtd / 60);
            $stats['yearTrendPct']  = $minYtdLast > 0 ? (int) round(($minYtd - $minYtdLast) / $minYtdLast * 100) : null;
            $stats['schulPct']      = $minYtd > 0 ? (int) round($minSchul / $minYtd * 100) : 0;
            $stats['yearLabel']     = $yearStart->format('Y');
            $stats['yearPrevLabel'] = $lastYearStart->format('Y');

            // Rohzeilen (id + mins) -> [{name, hours}]; Name-Resolver je Liste anders.
            $topList = function (array $rows, string $idCol, callable $name): array {
                return array_map(fn ($r) => ['name' => $name((int) $r[$idCol]), 'hours' => (int) round(((int) $r['mins']) / 60)], $rows);
            };

            // Top-10 Flugzeuge nach gebuchten Stunden (YTD)
            $topPlaneRows = $conn->executeQuery(
                "SELECT aircraftid, SUM(TIMESTAMPDIFF(MINUTE, itemstart, itemstop)) AS mins FROM FRes_booking "
                . "WHERE clientid = :cid AND $statusSql AND itemstart >= :from AND itemstart < :to "
                . "GROUP BY aircraftid ORDER BY mins DESC LIMIT 10",
                ['cid' => $clientid, 'from' => $yearStart->format('Y-m-d H:i:s'), 'to' => $now->format('Y-m-d H:i:s')]
            )->fetchAllAssociative();
            $stats['topPlanes'] = $topList($topPlaneRows, 'aircraftid', fn ($id) => Planes::GetPlaneNameAndKennung($em, $clientid, $id));

            // Top-10 Piloten/Flugschueler nach gebuchten Stunden (YTD)
            $topPilotRows = $conn->executeQuery(
                "SELECT createdbyuserid AS uid, SUM(TIMESTAMPDIFF(MINUTE, itemstart, itemstop)) AS mins FROM FRes_booking "
                . "WHERE clientid = :cid AND $statusSql AND itemstart >= :from AND itemstart < :to "
                . "GROUP BY createdbyuserid ORDER BY mins DESC LIMIT 10",
                ['cid' => $clientid, 'from' => $yearStart->format('Y-m-d H:i:s'), 'to' => $now->format('Y-m-d H:i:s')]
            )->fetchAllAssociative();
            $stats['topPilots'] = $topList($topPilotRows, 'uid', fn ($id) => Users::GetUserName($em, $clientid, $id));

            // --- Weitere Vereins-Kennzahlen ---
            $fmt     = static fn (\DateTimeInterface $d): string => $d->format('Y-m-d H:i:s');
            $yearAgo = (clone $now)->modify('-12 months');
            $scalarSql = fn (string $sql, array $p) => $conn->executeQuery($sql, ['cid' => $clientid] + $p)->fetchOne();

            // Ø Buchungsdauer (YTD)
            $cntYtd = (int) $scalarSql(
                "SELECT COUNT(*) FROM FRes_booking WHERE clientid=:cid AND $statusSql AND itemstart>=:from AND itemstart<:to",
                ['from' => $fmt($yearStart), 'to' => $fmt($now)]
            );
            $stats['avgDuration'] = $cntYtd > 0 ? round($minYtd / $cntYtd / 60, 1) : 0.0;

            // Aktive/inaktive Mitglieder + Ø Stunden je aktivem Mitglied (rollierend 12 Monate)
            $min365 = $sumMin($yearAgo, $now);
            $activeMembers = (int) $scalarSql(
                "SELECT COUNT(DISTINCT createdbyuserid) FROM FRes_booking WHERE clientid=:cid AND $statusSql AND itemstart>=:from AND itemstart<=:to",
                ['from' => $fmt($yearAgo), 'to' => $fmt($now)]
            );
            $stats['activeMembers']   = $activeMembers;
            $stats['inactiveMembers'] = max(0, (int) $stats['users'] - $activeMembers);
            $stats['hoursPerMember']  = $activeMembers > 0 ? round($min365 / 60 / $activeMembers, 1) : 0.0;

            // Aktive Flugschüler (Schulungsbuchung in 12 Monaten)
            $stats['activeStudents'] = (int) $scalarSql(
                "SELECT COUNT(DISTINCT createdbyuserid) FROM FRes_booking WHERE clientid=:cid AND $statusSql AND flightpurposeid IN (2,5) AND itemstart>=:from AND itemstart<=:to",
                ['from' => $fmt($yearAgo), 'to' => $fmt($now)]
            );

            // Stornoquote (YTD): storniert / (storniert + aktiv)
            $cntStorno = (int) $scalarSql(
                "SELECT COUNT(*) FROM FRes_booking WHERE clientid=:cid AND status='storniert' AND itemstart>=:from AND itemstart<:to",
                ['from' => $fmt($yearStart), 'to' => $fmt($now)]
            );
            $stats['stornoPct'] = ($cntYtd + $cntStorno) > 0 ? (int) round($cntStorno * 100 / ($cntYtd + $cntStorno)) : 0;

            // Ø Vorlaufzeit in Tagen (Erstellung -> Flugtermin, YTD)
            $lead = $scalarSql(
                "SELECT AVG(DATEDIFF(itemstart, createdDate)) FROM FRes_booking WHERE clientid=:cid AND $statusSql AND itemstart>=:from AND itemstart<:to AND createdDate>'2000-01-01'",
                ['from' => $fmt($yearStart), 'to' => $fmt($now)]
            );
            $stats['leadDays'] = $lead !== null ? (int) round((float) $lead) : 0;

            // Wochenend-Anteil der Stunden (YTD); MySQL DAYOFWEEK: 1=So, 7=Sa
            $minWeekend = $sumMin($yearStart, $now, 'AND DAYOFWEEK(itemstart) IN (1,7)');
            $stats['weekendPct'] = $minYtd > 0 ? (int) round($minWeekend * 100 / $minYtd) : 0;

            // Solo-Anteil an der Schulung (YTD)
            $minSolo = $sumMin($yearStart, $now, 'AND flightpurposeid = 5');
            $stats['soloPct'] = $minSchul > 0 ? (int) round($minSolo * 100 / $minSchul) : 0;

            // Lizenz-Gültigkeit: Anteil Mitglieder (mit Lizenz) ohne abgelaufene Lizenz
            $licJoin = "FROM FRes_userLicences l JOIN FRes_accounts a ON a.id=l.accountid "
                     . "WHERE l.clientid=:cid AND (l.status<>'geloescht' OR l.status IS NULL) AND (a.status<>'geloescht' OR a.status IS NULL)";
            $mLic = (int) $scalarSql("SELECT COUNT(DISTINCT l.accountid) $licJoin", []);
            $mExp = (int) $scalarSql(
                "SELECT COUNT(DISTINCT l.accountid) $licJoin AND (l.validunlimited=0 OR l.validunlimited IS NULL) AND l.validuntil < :today",
                ['today' => $today->format('Y-m-d H:i:s')]
            );
            $stats['licValidPct'] = $mLic > 0 ? (int) round(($mLic - $mExp) * 100 / $mLic) : 0;

            // Auslastungsgrad je Flugzeug: gebuchte Std / fliegbare Tageslicht-Std (YTD)
            $daylightSec = 0;
            $dl = clone $yearStart;
            while ($dl < $now) {
                $daylightSec += TimeFunctions::GetDaylight((int) $dl->format('n'), (int) $dl->format('j'), (int) $dl->format('Y'));
                $dl->modify('+1 day');
            }
            $daylightHours = $daylightSec > 0 ? $daylightSec / 3600 : 0;
            foreach ($stats['topPlanes'] as $i => $p) {
                $stats['topPlanes'][$i]['util'] = $daylightHours > 0 ? min(100, (int) round($p['hours'] / $daylightHours * 100)) : 0;
            }
            // Ø Auslastung der Flotte: gebuchte Std / (Tageslicht-Std x Anzahl Flugzeuge)
            $stats['fleetUtil'] = ($daylightHours > 0 && (int) $stats['aircraft'] > 0)
                ? min(100, (int) round(($minYtd / 60) / ($daylightHours * (int) $stats['aircraft']) * 100)) : 0;

            // Top-10 Fluglehrer nach Schulungsstunden (YTD)
            $topFiRows = $conn->executeQuery(
                "SELECT flightinstructor AS uid, SUM(TIMESTAMPDIFF(MINUTE, itemstart, itemstop)) AS mins FROM FRes_booking "
                . "WHERE clientid = :cid AND $statusSql AND flightinstructor IS NOT NULL AND flightinstructor > 0 "
                . "AND itemstart >= :from AND itemstart < :to GROUP BY flightinstructor ORDER BY mins DESC LIMIT 10",
                ['cid' => $clientid, 'from' => $fmt($yearStart), 'to' => $fmt($now)]
            )->fetchAllAssociative();
            $stats['topFi'] = $topList($topFiRows, 'uid', fn ($id) => Users::GetUserName($em, $clientid, $id));

            // 12-Monats-Verlauf der Flugstunden (nur abgeschlossene Monate)
            $m12Start = (clone $curMonthStart)->modify('-12 months');
            $mRows = $conn->executeQuery(
                "SELECT DATE_FORMAT(itemstart,'%Y-%m') AS ym, SUM(TIMESTAMPDIFF(MINUTE, itemstart, itemstop)) AS mins FROM FRes_booking "
                . "WHERE clientid = :cid AND $statusSql AND itemstart >= :from AND itemstart < :to GROUP BY ym",
                ['cid' => $clientid, 'from' => $fmt($m12Start), 'to' => $fmt($curMonthStart)]
            )->fetchAllAssociative();
            $byYm = [];
            foreach ($mRows as $r) { $byYm[$r['ym']] = (int) round(((int) $r['mins']) / 60); }
            $series = [];
            $dm = clone $m12Start;
            for ($i = 0; $i < 12; $i++) {
                $series[] = ['label' => mb_substr(self::MONTHS_DE[(int) $dm->format('n')], 0, 3), 'h' => $byYm[$dm->format('Y-m')] ?? 0];
                $dm->modify('+1 month');
            }
            $stats['monthly']    = $series;
            $stats['monthlyMax'] = max(1, max(array_column($series, 'h')));
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
        $clientid = $loggedin_user->getClientid();
        $tz = new \DateTimeZone('Europe/Berlin');

        $ansicht = $request->query->get('ansicht', '14tage');
        if (!in_array($ansicht, ['tag', 'monat', '14tage'], true)) { $ansicht = '14tage'; }
        $off = (int) $request->query->get('off', 0);

        // Tagesansicht (Stunden) – eigener Reiter; Daten kommen clientseitig über
        // /api/availability. Hier nur Flugzeug-/Fluglehrer-Liste + Default-Datum.
        if ($ansicht === 'tag') {
            $response = $this->render('modern/overview.html.twig', [
                'ansicht'     => 'tag',
                'planes'      => Planes::GetAllPlanesForMonthview($em, $clientid),
                'instructors' => Users::GetAllFlightinstructorsForListbox($em, $clientid),
                'today'       => (new \DateTime('today', $tz))->format('Y-m-d'),
                'label'       => '', 'prevOff' => 0, 'nextOff' => 0,
                'cells'       => [], 'days' => [], 'firows' => [], 'hasAvail' => false,
            ]);
            $response->setExpires(new \DateTime());

            return $response;
        }

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
        // Kompakter Tooltip je Zelle: pro Buchung eine Zeile "Zeit · Kennung · Kunde"
        // (kein Datum – der Tag ergibt sich aus der Spalte).
        // Voller Wochentagsname fuer die Tooltip-Kopfzeile (Wochentag + Datum).
        $wdFull = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $dateHead = static function (\DateTimeInterface $dt) use ($wdFull): string {
            return $wdFull[(int) $dt->format('N') - 1] . ', ' . $dt->format('d.m.Y');
        };

        $cells = [];
        foreach (($bookings ?? []) as $b) {
            $lines = [];
            foreach (($b['bookings'] ?? []) as $bk) {
                $lines[] = $bk['time'] . ' · ' . $bk['kennung'] . ' · ' . $bk['user'];
            }
            // Tooltip mit Kopfzeile "Wochentag, TT.MM.JJJJ" (Spalte = Tag).
            $bd   = \DateTime::createFromFormat('d-m-Y', (string) $b['bookingdate']);
            $head = $bd ? $dateHead($bd) . "\n" : '';
            $cells[$b['plane']][] = [
                'color'   => $b['color'],
                'tooltip' => $head . ($lines ? implode("\n", $lines) : 'frei'),
                'date'    => $b['bookingdate'],
            ];
        }

        // Tages-Header
        $wd = TimeFunctions::WEEKDAYS_SHORT;   // 1-indexiert (1=Mo)
        $todayStr = (new \DateTime('today', $tz))->format('Y-m-d');
        $days = [];
        $cur = clone $start;
        for ($i = 0; $i < $duration; $i++) {
            $dow = (int) $cur->format('N');
            $days[] = [
                'num'     => (int) $cur->format('j'),
                'wd'      => $wd[$dow],
                'wend'    => $dow === 6 ? 'sat' : ($dow === 7 ? 'sun' : ''),
                'today'   => $cur->format('Y-m-d') === $todayStr,
                'iso'     => $cur->format('Y-m-d'),
                'head'    => $dateHead($cur),
            ];
            $cur->modify('+1 day');
        }

        // --- Fluglehrer-Verfügbarkeit (grundsätzlich, aus den Schulungszeiten – OHNE
        //     Berücksichtigung von Buchungen), deckungsgleich mit der Flotten-Matrix.
        //     grün = verfügbar · orange = auf Anfrage (buchbar) · orange gestrichelt =
        //     auf Anfrage, nur nach Rücksprache (FIBookableIfOnRequest=false). ---
        $day0     = (clone $start)->setTime(0, 0, 0);
        $rangeEnd = (clone $start)->modify('+' . ($duration - 1) . ' days')->setTime(23, 59, 59);
        $avails = $em->createQuery(
            "SELECT a FROM App\Entity\FresFIAvailability a WHERE a.clientid = :cid "
            . "AND a.status <> :del AND a.itemstart <= :rend AND a.itemstop >= :rstart"
        )->setParameter('cid', $clientid)
         ->setParameter('del', FIAvailability::const_geloescht)
         ->setParameter('rstart', $day0)->setParameter('rend', $rangeEnd)->getResult();

        // rot (nicht verfügbar) hat die niedrigste Prio -> wird nur angezeigt, wenn am
        // Tag kein verfügbar-/Anfrage-Fenster existiert (sonst gewinnt die Verfügbarkeit).
        $prio = ['green' => 4, 'amber' => 3, 'amberdash' => 2, 'red' => 1];
        $fiData = [];
        $bookable = [];
        foreach ($avails as $a) {
            $fiObj = $a->getFlightinstructor();
            if (!$fiObj) { continue; }
            $fiId  = (int) $fiObj->getId();
            $typ   = $a->getTyp();
            $typId = $typ ? (int) $typ->getId() : 0;
            if ($typId === 3) {
                $state = 'red';                 // nicht verfügbar
            } elseif ($typId === 1) {
                $state = 'green';               // verfügbar
            } else {
                if (!array_key_exists($fiId, $bookable)) {
                    $bookable[$fiId] = Users::IsFlightinstructorBookableOnRequest($em, $fiId);
                }
                $state = $bookable[$fiId] ? 'amber' : 'amberdash';
            }
            $typName = $typ ? $typ->getName() : '';
            $comment = trim((string) $a->getComment());

            $si = (int) $day0->diff((clone $a->getItemstart())->setTime(0, 0, 0))->format('%r%a');
            $ei = (int) $day0->diff((clone $a->getItemstop())->setTime(0, 0, 0))->format('%r%a');
            for ($i = max(0, $si); $i <= min($duration - 1, $ei); $i++) {
                if (!isset($fiData[$fiId][$i])) { $fiData[$fiId][$i] = ['state' => $state, 'wins' => []]; }
                if ($prio[$state] > $prio[$fiData[$fiId][$i]['state']]) { $fiData[$fiId][$i]['state'] = $state; }
                $fiData[$fiId][$i]['wins'][] = $a->getItemstart()->format('H:i') . '–' . $a->getItemstop()->format('H:i') . ' ' . $typName . ($comment !== '' ? ' – ' . $comment : '');
            }
        }

        $firows = [];
        $hasAvail = false;
        foreach (Users::GetAllFlightinstructorsForListbox($em, $clientid) as $name => $fiId) {
            $fiId = (int) $fiId;
            $cellsFi = [];
            for ($i = 0; $i < $duration; $i++) {
                if (isset($fiData[$fiId][$i])) {
                    $hasAvail = true;
                    $cellsFi[$i] = [
                        'state' => $fiData[$fiId][$i]['state'],
                        // Kopfzeile "Wochentag, TT.MM.JJJJ", dann pro Zeitfenster eine Zeile
                        'title' => $days[$i]['head'] . "\n" . implode("\n", $fiData[$fiId][$i]['wins']),
                    ];
                } else {
                    $cellsFi[$i] = null;
                }
            }
            $firows[] = ['name' => $name, 'cells' => $cellsFi];
        }

        $response = $this->render('modern/overview.html.twig', [
            'ansicht'  => $ansicht,
            'label'    => $label,
            'prevOff'  => $prevOff,
            'nextOff'  => $nextOff,
            'planes'      => $planes,
            'cells'       => $cells,
            'days'        => $days,
            'firows'      => $firows,
            'hasAvail'    => $hasAvail,
            'instructors' => Users::GetAllFlightinstructorsForListbox($em, $clientid),
            'today'       => $todayStr,
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
            'dateIso'   => $date->format('Y-m-d'),
            'label'     => self::WEEKDAYS_DE[(int) $date->format('N')] . ', ' . $date->format('d.m.Y'),
            'prev'      => (clone $date)->modify('-1 day')->format('Y-m-d'),
            'next'      => (clone $date)->modify('+1 day')->format('Y-m-d'),
            'bookings'  => $bookings,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /**
     * Fluglehrer-Verfuegbarkeit als 7-Tage-Wochenmatrix (alle Fluglehrer) plus
     * Tab "Meine Verfuegbarkeiten" fuer Fluglehrer. Woche ueber ?week=YYYY-MM-DD
     * (auf Montag gesnappt). Daten aus FIAvailability::GetAvailabilitiesForRange.
     */
    public function fiWeek(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $clientid = $loggedin_user->getClientid();
        $tz = new \DateTimeZone('Europe/Berlin');
        $wdShort = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

        $ref = \DateTime::createFromFormat('Y-m-d', (string) $request->query->get('week', ''), $tz) ?: new \DateTime('today', $tz);
        $ref->setTime(0, 0, 0);
        $monday = (clone $ref)->modify('monday this week');
        $weekEnd = (clone $monday)->modify('+7 days');

        $instructors = Users::GetAllFlightinstructorsForListbox($em, $clientid);   // name => id
        $avails = FIAvailability::GetAvailabilitiesForRange($em, $clientid, $monday, $weekEnd);

        // je Fluglehrer -> Tag (0..6) -> Fenster {s,e,st}; typ 2 = auf Anfrage
        $byFi = [];
        foreach ($avails as $a) {
            $fid = (int) $a->getFlightinstructor();
            $st  = ((int) $a->getTyp() === 2) ? 'anfrageD' : 'frei';
            for ($d = 0; $d < 7; $d++) {
                $dayStart = (clone $monday)->modify("+$d days");
                $dayEnd   = (clone $dayStart)->modify('+1 day');
                $s = ($a->getItemstart() > $dayStart) ? $a->getItemstart() : $dayStart;
                $e = ($a->getItemstop()  < $dayEnd)   ? $a->getItemstop()  : $dayEnd;
                if ($s < $e) {
                    $end = $e->format('H:i');
                    $byFi[$fid][$d][] = ['s' => $s->format('H:i'), 'e' => ($end === '00:00' ? '24:00' : $end), 'st' => $st];
                }
            }
        }

        $data = [];
        foreach ($instructors as $name => $id) {
            $id = (int) $id;
            $days = [];
            for ($d = 0; $d < 7; $d++) { $days[$d] = $byFi[$id][$d] ?? []; }
            $data[] = ['id' => $id, 'name' => $name, 'days' => $days];
        }

        $dayLabels = [];
        for ($d = 0; $d < 7; $d++) {
            $dt = (clone $monday)->modify("+$d days");
            $dayLabels[] = ['wd' => $wdShort[(int) $dt->format('N')], 'dd' => $dt->format('d.m.')];
        }

        $response = $this->render('modern/fiweek.html.twig', [
            'data'      => $data,
            'dayLabels' => $dayLabels,
            'monday'    => $monday->format('Y-m-d'),
            'prevWeek'  => (clone $monday)->modify('-7 days')->format('Y-m-d'),
            'nextWeek'  => (clone $monday)->modify('+7 days')->format('Y-m-d'),
            'weekLabel' => $monday->format('d.m.') . '–' . (clone $monday)->modify('+6 days')->format('d.m.Y'),
            'myId'      => (int) $loggedin_user->getId(),
            'isFi'      => $this->isGranted('ROLE_FI'),
        ]);
        $response->setExpires(new \DateTime());
        return $response;
    }

    /** Einzelne Buchung im Detail (modern). Nutzt Bookings::GetBookingDetails. */
    public function booking(int $id, UserInterface $loggedin_user, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');

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
            'nextslotsDays' => Clients::GetNextslotsDays($em, $clientid),
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
        $vu = Licenses::ChangeValidUntil_Null($ul->getValiduntil());

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
            $ul->setValiduntil(Licenses::ChangeValidUntil_NotNull());
        }
        // One-to-One-Beziehungen muessen gesetzt sein (sonst kein Persist)
        $ul->setUser(Users::GetUserObject($em, $clientid, $accountid));
        $ul->setLicence(Licenses::GetLicenceTypeObject($em, $licenceid));

        $em->persist($ul);
        $em->flush();

        // --- Info-Mail (darf den Speichervorgang nicht abbrechen) ---
        $parameter = $this->mailParams();
        try {
            Licenses::SendLicenceInfoMail($em, $loggedin_user, $twig, $ul, $ul_old, $mailer, $parameter);
        } catch (\Throwable $e) {
            // Mailversand fehlgeschlagen – Lizenz ist trotzdem gespeichert.
        }

        return $this->redirectToRoute('modern_licences', $accountid !== $myId ? ['scope' => 'alle'] : []);
    }

    /** Lizenz loeschen (Soft-Delete) – bildet LicenceController::DeleteAction nach. */
    public function licenceDelete(MailerInterface $mailer, Environment $twig, Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $id       = (int) $request->request->get('id', 0);

        $ul = Licenses::GetUserLicenceObject($em, $clientid, $id);
        if (!$ul) {
            throw $this->createNotFoundException('Lizenz nicht gefunden.');
        }
        // Nur eigene Lizenz oder Admin
        if (!$this->isGranted('ROLE_ADMIN') && (int) $ul->getAccountid() !== $myId) {
            throw $this->createAccessDeniedException();
        }
        $accountid = (int) $ul->getAccountid();

        // Info-Mail ueber die Loeschung (darf den Vorgang nicht abbrechen)
        try {
            Licenses::SendLicenceInfoMail($em, $loggedin_user, $twig, null, $ul, $mailer, $this->mailParams());
        } catch (\Throwable $e) {
            // Mailversand fehlgeschlagen – Lizenz wird trotzdem geloescht.
        }

        Licenses::DeleteLicence($em, $clientid, $id);

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

    // ==================================================================
    //  Lizenztypen (global, nur ROLE_GLOBAL_ADMIN) – aus dem klassischen
    //  EditLicenceTypeController ins moderne Frontend uebernommen.
    // ==================================================================

    /** Liste der Lizenztypen. */
    public function licenceTypes(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $rows = $em->createQuery(
            "SELECT b FROM App\Entity\FresLicencetype b WHERE (b.status <> 'geloescht' OR b.status IS NULL) "
            . "ORDER BY b.categoryid ASC, b.longname ASC"
        )->getResult();
        $items = array_map(fn ($t) => [
            'id'           => (int) $t->getId(),
            'categoryid'   => $t->getCategoryid(),
            'categoryname' => (string) $t->getCategoryname(),
            'longname'     => (string) $t->getLongname(),
            'description'  => (string) $t->getDescription(),
        ], $rows);

        $response = $this->render('modern/licencetypes.html.twig', ['items' => $items]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Lizenztyp-Formular (Neu). */
    public function licenceTypeNew(UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        return $this->renderLicenceTypeForm(['id' => 0, 'categoryid' => '', 'categoryname' => '', 'longname' => '', 'description' => ''], []);
    }

    /** Lizenztyp-Formular (Bearbeiten). */
    public function licenceTypeEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $t = $em->getRepository(FresLicencetype::class)->find($id);
        if (!$t) {
            throw $this->createNotFoundException('Lizenztyp nicht gefunden.');
        }

        return $this->renderLicenceTypeForm([
            'id'           => (int) $t->getId(),
            'categoryid'   => $t->getCategoryid(),
            'categoryname' => (string) $t->getCategoryname(),
            'longname'     => (string) $t->getLongname(),
            'description'  => (string) $t->getDescription(),
        ], []);
    }

    /** Lizenztyp speichern (Neu/Bearbeiten). */
    public function licenceTypeSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        $id          = (int) $request->request->get('id', 0);
        $categoryid  = trim((string) $request->request->get('categoryid', ''));
        $categoryname = trim((string) $request->request->get('categoryname', ''));
        $longname    = trim((string) $request->request->get('longname', ''));
        $description = trim((string) $request->request->get('description', ''));

        $t = $id ? $em->getRepository(FresLicencetype::class)->find($id) : new FresLicencetype();
        if ($id && !$t) {
            throw $this->createNotFoundException('Lizenztyp nicht gefunden.');
        }

        if ($longname === '') {
            return $this->renderLicenceTypeForm(compact('id', 'categoryid', 'categoryname', 'longname', 'description'), ['Bitte einen Namen (Langname) eingeben.']);
        }

        $t->setCategoryid($categoryid === '' ? null : (int) $categoryid);
        $t->setCategoryname($categoryname);
        $t->setLongname($longname);
        $t->setDescription($description);
        if ($id === 0) {
            $t->setStatus('0');
        }

        $em->persist($t);
        $em->flush();

        return $this->redirectToRoute('modern_licencetypes');
    }

    /** Lizenztyp loeschen (Soft-Delete: Status 'geloescht'). */
    public function licenceTypeDelete(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');
        $id = (int) $request->request->get('id', 0);
        if ($id) {
            Licensetype::SetLicensetypeToInactive($em, $id);
        }

        return $this->redirectToRoute('modern_licencetypes');
    }

    private function renderLicenceTypeForm(array $values, array $errors): Response
    {
        $response = $this->render('modern/licencetype_form.html.twig', [
            'isNew'  => ((int) ($values['id'] ?? 0)) === 0,
            'v'      => $values,
            'errors' => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    // ==================================================================
    //  "Meine Daten" – Selbstbearbeitung des eigenen Profils (ROLE_PILOT),
    //  aus dem klassischen EditUserController::MyDataAction uebernommen.
    //  Bewusst OHNE Rollen/Sperre/Nutzername – nur persoenliche Angaben.
    // ==================================================================

    /** Eigenes Profil anzeigen. */
    public function mydata(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $u = Users::GetUserObject($em, $loggedin_user->getClientid(), (int) $loggedin_user->getId());

        return $this->renderMyData([
            'firstname'       => (string) $u->getFirstname(),
            'lastname'        => (string) $u->getLastname(),
            'username'        => (string) $u->getUsername(),
            'email'           => (string) $u->getEmail(),
            'phone_home'      => (string) $u->getPhoneNumberHome(),
            'phone_office'    => (string) $u->getPhoneNumberOffice(),
            'phone_mobile'    => (string) $u->getPhoneNumberMobile(),
            'getbookingmails' => (int) $u->getGetbookingmails(),
            'getlicencemails' => (int) $u->getGetlicencemails(),
        ], [], null);
    }

    /** Eigenes Profil speichern. */
    public function mydataSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user, UserPasswordHasherInterface $hasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PILOT');
        $u = Users::GetUserObject($em, $loggedin_user->getClientid(), (int) $loggedin_user->getId());

        $firstname = trim((string) $request->request->get('firstname', ''));
        $lastname  = trim((string) $request->request->get('lastname', ''));
        $email     = trim((string) $request->request->get('email', ''));
        $phoneH    = trim((string) $request->request->get('phone_home', ''));
        $phoneO    = trim((string) $request->request->get('phone_office', ''));
        $phoneM    = trim((string) $request->request->get('phone_mobile', ''));
        $bookmail  = (int) $request->request->get('getbookingmails', 0);
        $licmail   = (int) $request->request->get('getlicencemails', 0);
        $pass      = trim((string) $request->request->get('password', ''));
        $pass2     = trim((string) $request->request->get('password_check', ''));

        $errors = [];
        if ($firstname === '') { $errors[] = 'Bitte einen Vornamen eingeben.'; }
        if ($lastname === '')  { $errors[] = 'Bitte einen Nachnamen eingeben.'; }
        if ($email === '' || !Users::IsMailListValid($email)) { $errors[] = 'Die Mailadresse ist nicht gültig.'; }
        if ($pass !== '') {
            if ($pass !== $pass2) { $errors[] = 'Die Passwörter sind nicht identisch.'; }
            elseif (strlen($pass) < 5) { $errors[] = 'Das Passwort muss mindestens 5 Zeichen lang sein.'; }
        }

        $values = [
            'firstname' => $firstname, 'lastname' => $lastname, 'username' => (string) $u->getUsername(), 'email' => $email,
            'phone_home' => $phoneH, 'phone_office' => $phoneO, 'phone_mobile' => $phoneM,
            'getbookingmails' => $bookmail, 'getlicencemails' => $licmail,
        ];
        if ($errors) {
            return $this->renderMyData($values, $errors, null);
        }

        $u->setFirstname($firstname);
        $u->setLastname($lastname);
        $u->setEmail($email);
        $u->setPhoneNumberHome($phoneH);
        $u->setPhoneNumberOffice($phoneO);
        $u->setPhoneNumberMobile($phoneM);
        $u->setGetbookingmails($bookmail);
        $u->setGetlicencemails($licmail);
        if ($pass !== '') {
            $u->setPassword(Users::CreateNewPassword($loggedin_user, $hasher, $pass));
        }
        $em->persist($u);
        $em->flush();

        return $this->renderMyData($values, [], 'Deine Daten wurden gespeichert.');
    }

    private function renderMyData(array $values, array $errors, ?string $ok): Response
    {
        $response = $this->render('modern/mydata.html.twig', ['v' => $values, 'errors' => $errors, 'ok' => $ok]);
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
                // Rohtext; Zeilenumbrueche/Escaping macht das Template via |nl2br (XSS-sicher)
                'text'      => (string) $n->getDescription(),
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
        $clientid   = $loggedin_user->getClientid();
        $myId       = (int) $loggedin_user->getId();
        $isSysAdmin = $this->isGranted('ROLE_SYSTEM_ADMIN');
        $tz = new \DateTimeZone('Europe/Berlin');

        $id          = (int) $request->request->get('id', 0);
        $header      = trim((string) $request->request->get('header', ''));
        // WYSIWYG-HTML serverseitig auf eine sichere Whitelist reduzieren.
        $description = HtmlSanitizer::clean((string) $request->request->get('description', ''));
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
        if (HtmlSanitizer::toText($description) === '') { $errors[] = 'Bitte geben Sie einen Text ein.'; }
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
        $values = ['id' => 0, 'kennung' => '', 'name' => '', 'aircrafttype' => 0, 'advance' => '', 'active' => true];

        return $this->renderAircraftForm($em, $loggedin_user, $values, []);
    }

    /** Flugzeug-Formular (Bearbeiten). */
    public function aircraftEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');

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
        $values = ['id' => 0, 'short' => '', 'long' => '', 'licences' => []];

        return $this->renderTypeForm($em, $loggedin_user, $values, []);
    }

    /** Flugzeugtyp-Formular (Bearbeiten). */
    public function aircraftTypeEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SYSTEM_ADMIN');

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
                'nextslots_days' => $c->getNextslotsDays(),
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

        return $this->renderMandantForm(['id' => 0, 'name' => '', 'active' => true, 'nextslots_days' => 14], []);
    }

    /** Mandant-Formular (Bearbeiten). */
    public function mandantEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        $c = $em->getRepository(FresClient::class)->find($id);
        if (!$c) {
            throw $this->createNotFoundException('Mandant nicht gefunden.');
        }

        return $this->renderMandantForm(['id' => $c->getId(), 'name' => (string) $c->getName(), 'active' => $c->isActive(), 'nextslots_days' => $c->getNextslotsDays()], []);
    }

    /** Mandant speichern (Neu/Bearbeiten). */
    public function mandantSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

        $id     = (int) $request->request->get('id', 0);
        $name   = trim((string) $request->request->get('name', ''));
        $active = (bool) $request->request->get('active', false);
        // Vorausschau-Tage fuer "Naechste freie Termine" (1..120, Default 14).
        $days   = (int) $request->request->get('nextslots_days', 14);
        if ($days < 1)   { $days = 1; }
        if ($days > 120) { $days = 120; }

        if ($id !== 0) {
            $c = $em->getRepository(FresClient::class)->find($id);
            if (!$c) {
                throw $this->createNotFoundException('Mandant nicht gefunden.');
            }
        } else {
            $c = new FresClient();
        }

        if ($name === '') {
            return $this->renderMandantForm(['id' => $id, 'name' => $name, 'active' => $active, 'nextslots_days' => $days], ['Bitte einen Namen eingeben.']);
        }

        $c->setName($name);
        $c->setActive($active);
        $c->setNextslotsDays($days);
        $em->persist($c);
        $em->flush();

        return $this->redirectToRoute('modern_mandanten');
    }

    /** Mandant (de)aktivieren. */
    public function mandantToggle(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_GLOBAL_ADMIN');

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

    // ====================================================================
    //  Fluglehrer: verfügbare Schulungszeiten (ROLE_FI). Nutzt das bestehende
    //  Modell FresFIAvailability + die FIAvailability-Logik (Status, Überlappung).
    // ====================================================================

    private const FIAVAIL_STATE = [1 => 'green', 2 => 'amber', 3 => 'red'];

    /** Liste der Schulungszeiten (eigene; Admins zusätzlich „Alle"). */
    public function fiAvail(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');
        $isFi     = Users::isFlightinstructor($em, $myId);

        $scope = (string) $request->query->get('scope', 'meine');
        if (!$isAdmin || !in_array($scope, ['meine', 'alle'], true)) { $scope = 'meine'; }
        if ($isAdmin && !$isFi) { $scope = 'alle'; }   // Admin ohne FI-Rolle: nur „Alle" sinnvoll

        $since = (new \DateTime('today'))->modify('-1 day');
        $dql = "SELECT a FROM App\Entity\FresFIAvailability a WHERE a.clientid = :cid "
             . "AND a.status <> :del AND a.itemstop >= :since";
        if ($scope === 'meine') { $dql .= ' AND a.flightinstructor = :uid'; }
        $dql .= ' ORDER BY a.itemstart ASC';
        $q = $em->createQuery($dql)
                ->setParameter('cid', $clientid)
                ->setParameter('del', FIAvailability::const_geloescht)
                ->setParameter('since', $since);
        if ($scope === 'meine') { $q->setParameter('uid', $myId); }

        $wd = TimeFunctions::WEEKDAYS_SHORT;   // 1-indexiert (1=Mo)
        $items = [];
        foreach ($q->getResult() as $a) {
            $start = $a->getItemstart();
            $stop  = $a->getItemstop();
            $typ   = $a->getTyp();
            $fi    = $a->getFlightinstructor();
            $sameDay = $start->format('Y-m-d') === $stop->format('Y-m-d');
            $items[] = [
                'id'      => $a->getId(),
                'state'   => self::FIAVAIL_STATE[$typ ? (int) $typ->getId() : 0] ?? 'amber',
                'typname' => $typ ? $typ->getName() : '',
                'von'     => $wd[(int) $start->format('N')] . ' ' . $start->format('d.m.Y') . ' · ' . $start->format('H:i'),
                'bis'     => $sameDay ? $stop->format('H:i') . ' Uhr' : ($wd[(int) $stop->format('N')] . ' ' . $stop->format('d.m.Y') . ' · ' . $stop->format('H:i')),
                'comment' => trim((string) $a->getComment()),
                'fi'      => $fi ? trim($fi->getFirstname() . ' ' . $fi->getLastname()) : '',
            ];
        }

        // Ergebnis-Hinweis nach Serien-Anlage / Mehrfach-Löschen
        $created = (int) $request->query->get('created', 0);
        $skipped = (int) $request->query->get('skipped', 0);
        $deleted = (int) $request->query->get('deleted', 0);
        $notice = null;
        if ($created || $skipped) {
            $notice = $created . ' Termin(e) angelegt' . ($skipped ? ', ' . $skipped . ' wegen Überschneidung übersprungen' : '') . '.';
        } elseif ($deleted) {
            $notice = $deleted . ' Termin(e) gelöscht.';
        }

        $response = $this->render('modern/fiavail.html.twig', [
            'items'   => $items,
            'scope'   => $scope,
            'isAdmin' => $isAdmin,
            'isFi'    => $isFi,
            'notice'  => $notice,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Schulungszeit-Formular (Neu). */
    public function fiAvailNew(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $isFi  = Users::isFlightinstructor($em, (int) $loggedin_user->getId());
        $today = (new \DateTime('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');

        $values = [
            'id'      => 0,
            'fi'      => $isFi ? (int) $loggedin_user->getId() : 0,
            'typ'     => 1,
            'vondate' => $today, 'vontime' => '09:00',
            'bisdate' => $today, 'bistime' => '20:30',
            'comment' => '',
        ];

        return $this->renderFiAvailForm($em, $loggedin_user, $values, []);
    }

    /** Schulungszeit-Formular (Bearbeiten). */
    public function fiAvailEdit(int $id, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');

        $a = FIAvailability::GetAvailabilityObject($em, $loggedin_user->getClientid(), $id);
        if (!$a || (string) $a->getStatus() === FIAvailability::const_geloescht) {
            throw $this->createNotFoundException('Eintrag nicht gefunden.');
        }
        $fiObj = $a->getFlightinstructor();
        $fiId  = $fiObj ? (int) $fiObj->getId() : 0;
        if (!$this->isGranted('ROLE_ADMIN') && $fiId !== (int) $loggedin_user->getId()) {
            throw $this->createAccessDeniedException();
        }
        $typ = $a->getTyp();
        $values = [
            'id'      => $a->getId(),
            'fi'      => $fiId,
            'typ'     => $typ ? (int) $typ->getId() : 1,
            'vondate' => $a->getItemstart()->format('Y-m-d'), 'vontime' => $a->getItemstart()->format('H:i'),
            'bisdate' => $a->getItemstop()->format('Y-m-d'),  'bistime' => $a->getItemstop()->format('H:i'),
            'comment' => (string) $a->getComment(),
        ];

        return $this->renderFiAvailForm($em, $loggedin_user, $values, []);
    }

    /** Schulungszeit speichern (Neu/Bearbeiten). */
    public function fiAvailSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');
        $tz = new \DateTimeZone('Europe/Berlin');

        $id      = (int) $request->request->get('id', 0);
        $typId   = (int) $request->request->get('typ', 0);
        $vondate = trim((string) $request->request->get('vondate', ''));
        $vontime = trim((string) $request->request->get('vontime', ''));
        $bisdate = trim((string) $request->request->get('bisdate', ''));
        $bistime = trim((string) $request->request->get('bistime', ''));
        $comment = trim((string) $request->request->get('comment', ''));
        $fiPost  = (int) $request->request->get('fi', 0);

        if ($id !== 0) {
            $a = FIAvailability::GetAvailabilityObject($em, $clientid, $id);
            if (!$a || (string) $a->getStatus() === FIAvailability::const_geloescht) {
                throw $this->createNotFoundException('Eintrag nicht gefunden.');
            }
            $cur = $a->getFlightinstructor();
            $curId = $cur ? (int) $cur->getId() : 0;
            if (!$isAdmin && $curId !== $myId) {
                throw $this->createAccessDeniedException();
            }
            $fiId = $isAdmin ? ($fiPost ?: $curId) : $curId;
        } else {
            $a = new FresFIAvailability();
            $a->setStatus(0);
            $fiId = $isAdmin ? ($fiPost ?: $myId) : $myId;
        }

        $start = \DateTime::createFromFormat('Y-m-d H:i', $vondate . ' ' . $vontime, $tz);
        $end   = \DateTime::createFromFormat('Y-m-d H:i', $bisdate . ' ' . $bistime, $tz);

        $a->setClientid($clientid);
        $a->setFlightinstructor($fiId);   // ID für die Überlappungsprüfung
        $a->setItemstart($start ?: null);
        $a->setItemstop($end ?: null);
        $a->setComment($comment !== '' ? $comment : null);

        $errors = [];
        if (!$start || !$end) {
            $errors[] = 'Bitte gültige Von-/Bis-Zeiten angeben.';
        } elseif ($start >= $end) {
            $errors[] = 'Das Ende muss später als der Start sein.';
        }
        if ($typId <= 0) {
            $errors[] = 'Bitte einen Typ wählen.';
        }
        if (!$errors) {
            $overlap = FIAvailability::IsOverlapping($em, $a);
            if ($overlap !== null) { $errors[] = $overlap; }
        }

        if ($errors) {
            $values = [
                'id' => $id, 'fi' => $fiId, 'typ' => $typId,
                'vondate' => $vondate, 'vontime' => $vontime,
                'bisdate' => $bisdate, 'bistime' => $bistime, 'comment' => $comment,
            ];
            return $this->renderFiAvailForm($em, $loggedin_user, $values, $errors);
        }

        // One-to-One-Beziehungen setzen (sonst kein Persist)
        $a->setTyp(FIAvailability::GetAvailabilityStateObject($em, $typId));
        $a->setFlightinstructor(Users::GetUserObject($em, $clientid, $fiId));

        $em->persist($a);
        $em->flush();

        return $this->redirectToRoute('modern_fiavail', $fiId !== $myId ? ['scope' => 'alle'] : []);
    }

    /** Schulungszeit (soft) löschen. */
    public function fiAvailDelete(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $clientid = $loggedin_user->getClientid();

        $id = (int) $request->request->get('id', 0);
        $a  = $id ? FIAvailability::GetAvailabilityObject($em, $clientid, $id) : null;
        if ($a && (string) $a->getStatus() !== FIAvailability::const_geloescht) {
            $fi = $a->getFlightinstructor();
            $fiId = $fi ? (int) $fi->getId() : 0;
            if ($this->isGranted('ROLE_ADMIN') || $fiId === (int) $loggedin_user->getId()) {
                FIAvailability::DeleteAvailability($em, $clientid, $id);
            }
        }

        return $this->redirectToRoute('modern_fiavail');
    }

    private function renderFiAvailForm(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $clientid = $loggedin_user->getClientid();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');

        $response = $this->render('modern/fiavail_form.html.twig', [
            'isNew'       => ((int) ($values['id'] ?? 0)) === 0,
            'isAdmin'     => $isAdmin,
            'states'      => FIAvailability::GetAllAvailabilityStates($em),
            'instructors' => $isAdmin ? Users::GetAllFlightinstructorsForListbox($em, $clientid) : [],
            'fiName'      => Users::GetUserName($em, $clientid, (int) ($values['fi'] ?? 0)),
            'today'       => (new \DateTime('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            'v'           => $values,
            'errors'      => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Serien-Formular (z. B. „jeden Samstag, Aug–Dez"). */
    public function fiAvailSeries(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $tz = new \DateTimeZone('Europe/Berlin');
        $isFi = Users::isFlightinstructor($em, (int) $loggedin_user->getId());

        $values = [
            'fi'       => $isFi ? (int) $loggedin_user->getId() : 0,
            'typ'      => 1,
            'fromdate' => (new \DateTime('today', $tz))->format('Y-m-d'),
            'todate'   => (new \DateTime('today', $tz))->format('Y') . '-12-31',
            'weekdays' => [],
            'fromtime' => '09:00',
            'totime'   => '20:30',
            'comment'  => '',
        ];

        return $this->renderFiAvailSeries($em, $loggedin_user, $values, []);
    }

    /** Serie speichern: in Einzeltermine je passendem Wochentag zerlegen. */
    public function fiAvailSeriesSave(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');
        $tz = new \DateTimeZone('Europe/Berlin');

        $typId    = (int) $request->request->get('typ', 0);
        $fromdate = trim((string) $request->request->get('fromdate', ''));
        $todate   = trim((string) $request->request->get('todate', ''));
        $fromtime = trim((string) $request->request->get('fromtime', ''));
        $totime   = trim((string) $request->request->get('totime', ''));
        $comment  = trim((string) $request->request->get('comment', ''));
        $weekdays = array_values(array_filter(array_map('intval', (array) $request->request->all('weekdays')), fn ($d) => $d >= 1 && $d <= 7));
        $fiId     = $isAdmin ? ((int) $request->request->get('fi', 0) ?: $myId) : $myId;

        $from = \DateTime::createFromFormat('Y-m-d', $fromdate, $tz);
        $to   = \DateTime::createFromFormat('Y-m-d', $todate, $tz);
        if ($from) { $from->setTime(0, 0, 0); }
        if ($to)   { $to->setTime(0, 0, 0); }

        $errors = [];
        if ($typId <= 0)        { $errors[] = 'Bitte einen Typ wählen.'; }
        if (!$from || !$to)     { $errors[] = 'Bitte gültigen Zeitraum (von/bis Datum) angeben.'; }
        elseif ($from > $to)    { $errors[] = 'Das End-Datum muss nach dem Start-Datum liegen.'; }
        elseif ((int) $from->diff($to)->format('%a') > 400) { $errors[] = 'Der Zeitraum darf höchstens etwa ein Jahr umfassen.'; }
        if (!$weekdays)         { $errors[] = 'Bitte mindestens einen Wochentag wählen.'; }
        if (!preg_match('/^\d{1,2}:\d{2}$/', $fromtime) || !preg_match('/^\d{1,2}:\d{2}$/', $totime) || $fromtime >= $totime) {
            $errors[] = 'Bitte eine gültige Tageszeit (von vor bis) angeben.';
        }

        if ($errors) {
            $values = [
                'fi' => $fiId, 'typ' => $typId, 'fromdate' => $fromdate, 'todate' => $todate,
                'weekdays' => $weekdays, 'fromtime' => $fromtime, 'totime' => $totime, 'comment' => $comment,
            ];
            return $this->renderFiAvailSeries($em, $loggedin_user, $values, $errors);
        }

        $typObj  = FIAvailability::GetAvailabilityStateObject($em, $typId);
        $userObj = Users::GetUserObject($em, $clientid, $fiId);
        $created = 0; $skipped = 0; $guard = 0;
        $cur = clone $from;
        while ($cur <= $to && $guard < 800) {
            $guard++;
            if (in_array((int) $cur->format('N'), $weekdays, true)) {
                $start = \DateTime::createFromFormat('Y-m-d H:i', $cur->format('Y-m-d') . ' ' . $fromtime, $tz);
                $end   = \DateTime::createFromFormat('Y-m-d H:i', $cur->format('Y-m-d') . ' ' . $totime, $tz);

                $a = new FresFIAvailability();
                $a->setClientid($clientid);
                $a->setStatus(0);
                $a->setFlightinstructor($fiId);
                $a->setItemstart($start);
                $a->setItemstop($end);
                $a->setComment($comment !== '' ? $comment : null);

                if (FIAvailability::IsOverlapping($em, $a) !== null) {
                    $skipped++;
                } else {
                    $a->setTyp($typObj);
                    $a->setFlightinstructor($userObj);
                    $em->persist($a);
                    $em->flush();   // einzeln, damit die Überlappungsprüfung folgende Tage erkennt
                    $created++;
                }
            }
            $cur->modify('+1 day');
        }

        return $this->redirectToRoute('modern_fiavail', array_merge(
            ['created' => $created, 'skipped' => $skipped],
            $fiId !== $myId ? ['scope' => 'alle'] : []
        ));
    }

    private function renderFiAvailSeries(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $response = $this->render('modern/fiavail_series.html.twig', [
            'isAdmin'     => $isAdmin,
            'states'      => FIAvailability::GetAllAvailabilityStates($em),
            'instructors' => $isAdmin ? Users::GetAllFlightinstructorsForListbox($em, $loggedin_user->getClientid()) : [],
            'today'       => (new \DateTime('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            'v'           => $values,
            'errors'      => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }

    /** Mehrfach-Löschen-Formular. */
    public function fiAvailBulk(EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $tz = new \DateTimeZone('Europe/Berlin');
        $isFi = Users::isFlightinstructor($em, (int) $loggedin_user->getId());

        $values = [
            'fi'       => $isFi ? (int) $loggedin_user->getId() : 0,
            'fromdate' => (new \DateTime('today', $tz))->format('Y-m-d'),
            'todate'   => (new \DateTime('today', $tz))->format('Y') . '-12-31',
            'weekdays' => [],
        ];

        return $this->renderFiAvailBulk($em, $loggedin_user, $values, []);
    }

    /** Mehrfach-Löschen ausführen (Soft-Delete aller passenden Termine). */
    public function fiAvailBulkDelete(Request $request, EntityManagerInterface $em, UserInterface $loggedin_user): Response
    {
        $this->denyAccessUnlessGranted('ROLE_FI');
        $clientid = $loggedin_user->getClientid();
        $myId     = (int) $loggedin_user->getId();
        $isAdmin  = $this->isGranted('ROLE_ADMIN');
        $tz = new \DateTimeZone('Europe/Berlin');

        $fromdate = trim((string) $request->request->get('fromdate', ''));
        $todate   = trim((string) $request->request->get('todate', ''));
        $weekdays = array_values(array_filter(array_map('intval', (array) $request->request->all('weekdays')), fn ($d) => $d >= 1 && $d <= 7));
        $fiId     = $isAdmin ? ((int) $request->request->get('fi', 0) ?: $myId) : $myId;

        $from = \DateTime::createFromFormat('Y-m-d', $fromdate, $tz);
        $to   = \DateTime::createFromFormat('Y-m-d', $todate, $tz);
        if ($from) { $from->setTime(0, 0, 0); }
        if ($to)   { $to->setTime(23, 59, 59); }

        $errors = [];
        if (!$from || !$to)  { $errors[] = 'Bitte gültigen Zeitraum (von/bis Datum) angeben.'; }
        elseif ($from > $to) { $errors[] = 'Das End-Datum muss nach dem Start-Datum liegen.'; }

        if ($errors) {
            $values = ['fi' => $fiId, 'fromdate' => $fromdate, 'todate' => $todate, 'weekdays' => $weekdays];
            return $this->renderFiAvailBulk($em, $loggedin_user, $values, $errors);
        }

        $rows = $em->createQuery(
            "SELECT a FROM App\Entity\FresFIAvailability a WHERE a.clientid = :cid AND a.flightinstructor = :fi "
            . "AND a.status <> :del AND a.itemstart >= :from AND a.itemstart <= :to"
        )->setParameter('cid', $clientid)->setParameter('fi', $fiId)
         ->setParameter('del', FIAvailability::const_geloescht)
         ->setParameter('from', $from)->setParameter('to', $to)->getResult();

        $deleted = 0;
        foreach ($rows as $a) {
            if ($weekdays && !in_array((int) $a->getItemstart()->format('N'), $weekdays, true)) {
                continue;   // Wochentag-Filter
            }
            $a->setStatus(FIAvailability::const_geloescht);
            $deleted++;
        }
        if ($deleted) { $em->flush(); }

        return $this->redirectToRoute('modern_fiavail', array_merge(
            ['deleted' => $deleted],
            $fiId !== $myId ? ['scope' => 'alle'] : []
        ));
    }

    private function renderFiAvailBulk(EntityManagerInterface $em, UserInterface $loggedin_user, array $values, array $errors): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $response = $this->render('modern/fiavail_bulk.html.twig', [
            'isAdmin'     => $isAdmin,
            'instructors' => $isAdmin ? Users::GetAllFlightinstructorsForListbox($em, $loggedin_user->getClientid()) : [],
            'today'       => (new \DateTime('today', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
            'v'           => $values,
            'errors'      => $errors,
        ]);
        $response->setExpires(new \DateTime());

        return $response;
    }
}
