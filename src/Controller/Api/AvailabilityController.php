<?php

namespace App\Controller\Api;

use App\Entities\Bookings;
use App\Entities\Clients;
use App\Entities\FlightPurposes;
use App\Entities\Planes;
use App\Entities\Users;
use App\TimeFunctions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verfuegbarkeit fuer den Reservieren-Bildschirm (Meilenstein M3).
 *
 *   GET /api/availability?date=YYYY-MM-DD&aircraft=<id>&fi=<id>
 *
 * Liefert die freien Zeitfenster fuer das gewaehlte Flugzeug und/oder den
 * gewaehlten Fluglehrer sowie deren Schnittmenge (die "gruenen Slots" im
 * Mockup).
 *
 * Wiederverwendung statt Duplizierung: Die eigentliche Verfuegbarkeitslogik
 * (Buchungs-Luecken der Flugzeuge bzw. FI-Verfuegbarkeit minus Buchungen)
 * steckt in den bestehenden Methoden Bookings::GetFreeGapsForPlaneInRange()
 * und Bookings::GetFIFreeWindowsForOneDay(), die strukturierte Daten liefern.
 * (Frueher wurde stattdessen der HTML-String der Alt-Ansichten geparst –
 * per Head-to-Head-Test als gleichwertig nachgewiesen und ersetzt.)
 */
class AvailabilityController extends ApiController
{
    /** GET /api/availability */
    public function availability(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $clientid = $user->getClientid();

        // --- Datum pruefen ---
        $dateStr = (string) $request->query->get('date', '');
        $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$date || $date->format('Y-m-d') !== $dateStr) {
            return $this->json(['error' => 'invalid_date', 'expected' => 'YYYY-MM-DD'], 400);
        }
        $date->setTime(0, 0, 0);

        // (int)-Cast statt getInt(): in Symfony 8 wirft getInt() bei nicht-numerischen
        // Werten (z. B. aircraft=all im Buendel-Modus unten) eine BadRequestException;
        // der Cast liefert wie frueher 0 und ueberlaesst die 'all'-Erkennung dem Code darunter.
        $aircraftId = (int) $request->query->get('aircraft', 0);
        $fiId       = (int) $request->query->get('fi', 0);

        // --- Tagesfenster (sonnenstandsbasiert, wie im Hauptprogramm) ---
        [$dsH, $dsM] = TimeFunctions::GetDayStart($date);
        [$deH, $deM] = TimeFunctions::GetDayEnd($date);
        $dayStart = $dsH * 60 + $dsM;
        $dayEnd   = $deH * 60 + $deM;

        // --- Buendel-Modus: alle Flugzeuge des Tages in EINER Antwort ---
        // Beschleunigt die Tagesansicht drastisch (sonst 1 Request je Flugzeug).
        if ($request->query->get('aircraft') === 'all') {
            $planeIds = array_map(
                static fn (array $p) => (int) $p['planeID'],
                Planes::GetAllPlanesForMonthview($em, $clientid)
            );
            $gaps  = Bookings::GetFreeGapsForAllPlanesOnDay($em, $clientid, $date, $planeIds);
            $acOut = [];
            foreach ($gaps as $pid => $slots) {
                $acOut[$pid] = $this->slotsOut($slots);
            }
            return $this->json([
                'date'     => $date->format('Y-m-d'),
                'dayStart' => $this->min2str($dayStart),
                'dayEnd'   => $this->min2str($dayEnd),
                'aircraft' => $acOut,
            ]);
        }

        // --- Verfuegbarkeit direkt als Daten (kein HTML-Parsing mehr) ---
        $dateStr  = $date->format('Y-m-d');
        $dayAfter = (clone $date)->modify('+1 day');

        // --- Flugzeug: freie Luecken fuer das gewaehlte Flugzeug ---
        $aircraftFree = null;
        if ($aircraftId) {
            $plane = Planes::GetPlaneObject($em, $clientid, $aircraftId);
            $aircraftFree = ($plane && $plane->getKennung() !== null)
                ? (Bookings::GetFreeGapsForPlaneInRange($em, $clientid, $aircraftId, $date, $dayAfter)[$dateStr] ?? [])
                : [];
        }

        // --- Fluglehrer: Verfuegbarkeitsfenster fuer den gewaehlten FI ([s,e] ohne Typ) ---
        $instructorFree = null;
        if ($fiId) {
            $instructorFree = array_map(
                static fn (array $w) => [$w[0], $w[1]],
                Bookings::GetFIFreeWindowsForOneDay($em, $clientid, $fiId, $date)
            );
        }

        // --- Fluglehrer-ZUSTAENDE (frei / auf Anfrage direkt / nach Absprache / nicht buchbar) ---
        $instructorSegments = $fiId
            ? $this->buildInstructorSegments($em, $clientid, $fiId, $date, $dayStart, $dayEnd)
            : null;

        // --- Gemeinsame freie Slots = Tagesfenster geschnitten mit den Auswahlen ---
        $slots = [[$dayStart, $dayEnd]];
        if ($aircraftFree !== null) {
            $slots = $this->intersect($slots, $aircraftFree);
        }
        if ($instructorFree !== null) {
            $slots = $this->intersect($slots, $instructorFree);
        }

        return $this->json([
            'date'           => $date->format('Y-m-d'),
            'dayStart'       => $this->min2str($dayStart),
            'dayEnd'         => $this->min2str($dayEnd),
            'aircraftId'     => $aircraftId ?: null,
            'aircraftFree'   => $aircraftFree === null ? null : $this->slotsOut($aircraftFree),
            'instructorId'       => $fiId ?: null,
            'instructorFree'     => $instructorFree === null ? null : $this->slotsOut($instructorFree),
            'instructorSegments' => $instructorSegments,
            'freeSlots'          => $this->slotsOut($slots, true),
        ]);
    }

    /**
     * Baut die Fluglehrer-Zustandssegmente (frei / auf Anfrage direkt / nach
     * Absprache / solo / ausgebucht / nicht verfuegbar) fuer EINEN Tag und FI.
     * Aus availability() ausgelagert, damit comparematrix() es fuer ALLE
     * Fluglehrer wiederverwenden kann. Basis: Bookings::GetFIFreeWindowsForOneDay
     * (strukturierte Daten – frueher aus geparstem HTML).
     *
     * @return array<int,array{start:string,end:string,state:string,note?:string}>
     */
    private function buildInstructorSegments(EntityManagerInterface $em, int $clientid, int $fiId, \DateTime $date, int $dayStart, int $dayEnd): array
    {
        // Nur Flag 1 (= echter Dummy-/immer-fuer-alle-Fluglehrer) gilt fuer die ANZEIGE
        // als ganztaegig frei. 2/3 (fuer sich selbst / Admins) sind reale Lehrer ->
        // echte Verfuegbarkeit anzeigen; die Sonder-Buchbarkeit bleibt Sache der Speichern-Pruefung.
        if (Users::IsFlightinstructorAlwaysAvailable($em, $fiId)) {
            // Dummy-Fluglehrer (Flag 1): ganzer Tag frei
            return [['start' => $this->min2str($dayStart), 'end' => $this->min2str($dayEnd), 'state' => 'frei']];
        }

        $onReq = Users::IsFlightinstructorBookableOnRequest($em, $fiId);
        // Basis-Fenster [startMin, endMin, typ] (1=frei, 2=auf Anfrage) direkt als Daten.
        $instructorSegments = array_map(function (array $w) use ($onReq) {
            $state = ((int) $w[2] === 2)
                ? ($onReq ? 'anfrage_direkt' : 'anfrage_absprache')
                : 'frei';
            return ['start' => $this->min2str($w[0]), 'end' => $this->min2str($w[1]), 'state' => $state];
        }, Bookings::GetFIFreeWindowsForOneDay($em, $clientid, $fiId, $date));

        // Individuelle Buchungslage des Lehrers an diesem Tag beruecksichtigen:
        // Eine Solo-Schulflug-Buchung bleibt bei FiParallelBookings=1 weiterhin
        // (fuer eine weitere Solo-Schulung) buchbar -> 'solo'. Jede andere Buchung
        // belegt den Lehrer -> 'ausgebucht' (grau schraeg gestrichelt). Diese
        // Segmente werden zuletzt angehaengt und ueberlagern so die frei-/Anfrage-
        // Bereiche genau dort, wo der Lehrer schon gebucht ist.
        $parallel = Users::AllowDoubleBookingsforFlightinstructor($em, $fiId);
        $bks = $em->createQuery(
            "SELECT b FROM App\Entity\FresBooking b WHERE b.clientid = :c AND b.flightinstructor = :fi "
            . "AND b.status NOT IN ('storniert','flugzeug_geloescht','user_geloescht') "
            . "AND b.itemstart < :to AND b.itemstop > :from"
        )->setParameters([
            'c' => $clientid, 'fi' => $fiId,
            'from' => $date->format('Y-m-d 00:00:00'),
            'to'   => (clone $date)->modify('+1 day')->format('Y-m-d 00:00:00'),
        ])->getResult();
        $dayStr = $date->format('Y-m-d');
        foreach ($bks as $b) {
            $bs = ($b->getItemstart()->format('Y-m-d') < $dayStr)
                ? $dayStart : (int) $b->getItemstart()->format('H') * 60 + (int) $b->getItemstart()->format('i');
            $be = ($b->getItemstop()->format('Y-m-d') > $dayStr)
                ? $dayEnd : (int) $b->getItemstop()->format('H') * 60 + (int) $b->getItemstop()->format('i');
            $bs = max($bs, $dayStart);
            $be = min($be, $dayEnd);
            if ($be <= $bs) {
                continue;
            }
            $isSolo = FlightPurposes::IsSolo($b->getFlightpurposeid());
            $state = ($isSolo && $parallel) ? 'solo' : 'ausgebucht';
            $instructorSegments[] = ['start' => $this->min2str($bs), 'end' => $this->min2str($be), 'state' => $state];
        }

        // "Nicht verfuegbar" (Typ 3) aus den Schulungszeiten: rot anzeigen, eine
        // hinterlegte Begruendung als 'note' (-> Tooltip). Zuletzt angehaengt, damit
        // es die frei-/Anfrage-Bereiche ueberlagert.
        $nv = $em->createQuery(
            "SELECT a FROM App\Entity\FresFIAvailability a WHERE a.clientid = :c AND a.flightinstructor = :fi "
            . "AND a.status <> 'geloescht' AND a.itemstart < :to AND a.itemstop > :from"
        )->setParameters([
            'c' => $clientid, 'fi' => $fiId,
            'from' => $date->format('Y-m-d 00:00:00'),
            'to'   => (clone $date)->modify('+1 day')->format('Y-m-d 00:00:00'),
        ])->getResult();
        foreach ($nv as $a) {
            $typ = $a->getTyp();
            if (!$typ || (int) $typ->getId() !== 3) {
                continue;   // nur "nicht verfuegbar"
            }
            $bs = ($a->getItemstart()->format('Y-m-d') < $dayStr)
                ? $dayStart : (int) $a->getItemstart()->format('H') * 60 + (int) $a->getItemstart()->format('i');
            $be = ($a->getItemstop()->format('Y-m-d') > $dayStr)
                ? $dayEnd : (int) $a->getItemstop()->format('H') * 60 + (int) $a->getItemstop()->format('i');
            $bs = max($bs, $dayStart);
            $be = min($be, $dayEnd);
            if ($be <= $bs) {
                continue;
            }
            $seg = ['start' => $this->min2str($bs), 'end' => $this->min2str($be), 'state' => 'nichtverfuegbar'];
            $note = trim((string) $a->getComment());
            if ($note !== '') {
                $seg['note'] = $note;
            }
            $instructorSegments[] = $seg;
        }

        return $instructorSegments;
    }

    /**
     * GET /api/comparematrix?date=<YYYY-MM-DD>&kind=ac|fi
     *
     * Batch-Variante fuer "Andere Flugzeuge/Fluglehrer vergleichen": berechnet
     * die Tagesverfuegbarkeit fuer ALLE Flugzeuge bzw. ALLE Fluglehrer in EINER
     * Anfrage. Frueher feuerte das Frontend N Einzelaufrufe an /api/availability,
     * von denen JEDER intern erneut alle Flugzeuge UND alle Lehrer berechnete
     * (O(N^2) + N Roundtrips). Hier wird die "alle"-Basis genau einmal gerechnet.
     *
     * Rueckgabe-Shapes sind identisch zu availability():
     *   kind=ac -> rows:[{id, free:[{start,end},...]}]
     *   kind=fi -> rows:[{id, segments:[{start,end,state,note?},...]}]
     */
    public function comparematrix(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $clientid = $user->getClientid();

        $dateStr = (string) $request->query->get('date', '');
        $date = \DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$date || $date->format('Y-m-d') !== $dateStr) {
            return $this->json(['error' => 'invalid_date', 'expected' => 'YYYY-MM-DD'], 400);
        }
        $date->setTime(0, 0, 0);
        $kind = $request->query->get('kind') === 'fi' ? 'fi' : 'ac';

        [$dsH, $dsM] = TimeFunctions::GetDayStart($date);
        [$deH, $deM] = TimeFunctions::GetDayEnd($date);
        $dayStart = $dsH * 60 + $dsM;
        $dayEnd   = $deH * 60 + $deM;

        $dateStr  = $date->format('Y-m-d');
        $dayAfter = (clone $date)->modify('+1 day');
        $rows = [];
        if ($kind === 'ac') {
            // Freie Luecken je Flugzeug direkt als Daten (kein HTML-Parsing).
            foreach (Planes::GetAllPlanesAsObject($em, $clientid) as $p) {
                $free = ($p->getKennung() !== null)
                    ? (Bookings::GetFreeGapsForPlaneInRange($em, $clientid, (int) $p->getId(), $date, $dayAfter)[$dateStr] ?? [])
                    : [];
                $rows[] = ['id' => (int) $p->getId(), 'free' => $this->slotsOut($free)];
            }
        } else {
            // Zustandssegmente je Fluglehrer direkt (buildInstructorSegments ist
            // selbst datenbasiert – keine geparsten HTML-Bloecke mehr noetig).
            foreach (Users::GetAllFlightinstructorsAsObject($em, $clientid) as $fi) {
                $fiId = (int) $fi->getId();
                $rows[] = [
                    'id'       => $fiId,
                    'segments' => $this->buildInstructorSegments($em, $clientid, $fiId, $date, $dayStart, $dayEnd),
                ];
            }
        }

        return $this->json([
            'date'     => $date->format('Y-m-d'),
            'dayStart' => $this->min2str($dayStart),
            'dayEnd'   => $this->min2str($dayEnd),
            'kind'     => $kind,
            'rows'     => $rows,
        ]);
    }

    /**
     * GET /api/nextslots?aircraft=<id>&fi=<id>
     *
     * Liefert die naechsten freien Zeitfenster fuer das Flugzeug (und optional
     * den Fluglehrer) ueber die kommenden Tage – fuer die "Naechste freie
     * Termine"-Box im Reservieren. Bricht frueh ab, sobald genug Fenster
     * gefunden sind. Gleiche freeSlots-Logik wie availability()
     * (Tagesfenster ∩ Flugzeug-frei ∩ Fluglehrer-frei).
     */
    public function nextslots(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $clientid = $user->getClientid();

        // (int)-Cast statt getInt(): in Symfony 8 wirft getInt() bei nicht-numerischen
        // Werten (z. B. aircraft=all im Buendel-Modus unten) eine BadRequestException;
        // der Cast liefert wie frueher 0 und ueberlaesst die 'all'-Erkennung dem Code darunter.
        $aircraftId = (int) $request->query->get('aircraft', 0);
        $fiId       = (int) $request->query->get('fi', 0);
        // Es muss mindestens ein Filter gesetzt sein: Flugzeug ODER Fluglehrer
        // (oder beides als Kombination). Ohne beides gibt es nichts anzuzeigen.
        if (!$aircraftId && !$fiId) {
            return $this->json(['slots' => []]);
        }

        // Flugzeug ist optional. Nur wenn eines gewaehlt ist, holen wir das Objekt
        // und die maximale Vorausbuchungszeit – die ist flugzeugspezifisch.
        // Maximale Vorausbuchungszeit des Flugzeugs (Tage; 0 = unbegrenzt) – exakt
        // dieselbe Regel wie beim Speichern (Planes::GetAdvanceBookingCutoff): ganze
        // Tage, der aeusserste Tag wird am Vorabend um 20:00 Uhr freigegeben. Ein Slot
        // jenseits dieser Grenze wird gar nicht erst vorgeschlagen.
        $maxBookDt = null;
        if ($aircraftId) {
            $plane = Planes::GetPlaneObject($em, $clientid, $aircraftId);
            if ($plane === null || $plane->getKennung() === null) {
                return $this->json(['slots' => []]);
            }
            // Admins sind – exakt wie beim Speichern (BookingController) – von der
            // Vorausbuchungsfrist ausgenommen; fuer alle anderen gilt dasselbe Fenster.
            $maxBookDt = $this->isGranted('ROLE_ADMIN')
                ? null
                : Planes::GetAdvanceBookingCutoff((int) $plane->getAdvancebooking());
        }

        $fiName      = null;
        $fiAlways    = false;
        // Ist der Fluglehrer bei "auf Anfrage"-Zeiten direkt buchbar (true) oder nur
        // nach vorheriger Absprache/Freigabe (false)? Steuert die Darstellung der
        // beiden Anfrage-Varianten – exakt dieselbe Regel wie bei der Buchungspruefung
        // (FIAvailability::CheckIfFIIsAvailable -> $onRequest).
        $fiOnRequest = false;
        if ($fiId) {
            $fi          = Users::GetUserObject($em, $clientid, $fiId);
            $fiName      = $fi ? ($fi->getFirstname() . ' ' . $fi->getLastname()) : null;
            $fiAlways    = Users::IsFlightinstructorAlwaysAvailable($em, $fiId);
            $fiOnRequest = Users::IsFlightinstructorBookableOnRequest($em, $fiId);
        }

        $maxDays = Clients::GetNextslotsDays($em, $clientid);   // Lookahead-Fenster pro Mandant konfigurierbar (Default 14)
        $want    = 8;    // so viele Fenster pro Charge ("Weitere anzeigen")
        $minMin  = 60;   // nur Bloecke ab 1 Std

        // Cursor fuer "Weitere anzeigen": nur Slots NACH diesem Zeitpunkt liefern.
        $afterDate = null; $afterMin = -1;
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{1,2}):(\d{2})$/', trim((string) $request->query->get('after', '')), $am)) {
            $afterDate = $am[1];
            $afterMin  = (int) $am[2] * 60 + (int) $am[3];
        }

        $today   = new \DateTime('today');
        $endDate = (clone $today)->modify('+' . $maxDays . ' day');
        $out     = [];

        // Flugzeug-Verfuegbarkeit fuer den GANZEN Zeitraum in EINER Abfrage holen
        // (statt pro Tag die Verfuegbarkeit aller Flugzeuge zu berechnen) -> schnell.
        // Nur noetig, wenn ein Flugzeug gewaehlt ist.
        $gapsByDay = $aircraftId
            ? Bookings::GetFreeGapsForPlaneInRange($em, $clientid, $aircraftId, $today, $endDate)
            : [];

        for ($i = 0; $i < $maxDays && count($out) < $want; $i++) {
            $date = (clone $today)->modify('+' . $i . ' day');
            // Ganzer Tag jenseits der Vorausbuchungsgrenze? -> ab hier nichts mehr buchbar.
            if ($maxBookDt !== null && $date > $maxBookDt) {
                break;
            }
            $ds = $date->format('Y-m-d');
            // Tage vor dem Cursor ueberspringen ("Weitere anzeigen")
            if ($afterDate !== null && $ds < $afterDate) {
                continue;
            }
            [$dsH, $dsM] = TimeFunctions::GetDayStart($date);
            [$deH, $deM] = TimeFunctions::GetDayEnd($date);
            $dayStart = $dsH * 60 + $dsM;
            $dayEnd   = $deH * 60 + $deM;
            $floor    = max(540, $dayStart);   // wie im Frontend ab 09:00

            // Alle Slots tragen den Verfuegbarkeits-Typ mit: 1 = frei, 2 = auf Anfrage.
            if ($aircraftId) {
                // Basis: freie Luecken des Flugzeugs (schon tagesgefenstert) – immer "frei".
                $slots = [];
                foreach ($gapsByDay[$ds] ?? [] as $g) {
                    $slots[] = [$g[0], $g[1], 1];
                }
                // Kombination: zusaetzlich mit der Verfuegbarkeit des gewaehlten
                // Fluglehrers schneiden (nur den einen FI rechnen -> schnell). Das
                // Ergebnis erbt den FI-Typ: ist der FI nur auf Anfrage verfuegbar,
                // ist auch der Termin nur auf Anfrage buchbar.
                if ($fiId && !$fiAlways) {
                    $instructorFree = Bookings::GetFIFreeWindowsForOneDay($em, $clientid, $fiId, $date);
                    $slots          = $this->intersectWithType($slots, $instructorFree);
                }
            } else {
                // Nur Fluglehrer gewaehlt: seine Fenster sind die Basis (inkl. Typ).
                // Ein "immer verfuegbarer" FI gilt den ganzen Tag als frei (Typ 1).
                $slots = $fiAlways
                    ? [[$dayStart, $dayEnd, 1]]
                    : Bookings::GetFIFreeWindowsForOneDay($em, $clientid, $fiId, $date);
            }

            foreach ($slots as $s) {
                $start = (int) (ceil(max($s[0], $floor) / 30) * 30);
                $end   = (int) (floor(min($s[1], $dayEnd) / 30) * 30);
                if ($end - $start < $minMin) {
                    continue;
                }
                // bereits gezeigte Slots am Cursor-Tag ueberspringen ("Weitere anzeigen")
                if ($afterDate !== null && $ds === $afterDate && $start <= $afterMin) {
                    continue;
                }
                // Slot-Start jenseits der Vorausbuchungsgrenze (Randtag)? -> nicht vorschlagen.
                if ($maxBookDt !== null) {
                    $slotStart = \DateTime::createFromFormat('Y-m-d H:i', $ds . ' ' . $this->min2str($start));
                    if ($slotStart && $slotStart > $maxBookDt) {
                        continue;
                    }
                }
                // Drei Zustaende, passend zur Buchbarkeit:
                //  'frei'      = Typ 1               -> direkt buchbar
                //  'anfrage'   = Typ 2 + FI buchbar  -> auf Anfrage, aber buchbar
                //  'absprache' = Typ 2 + FI n.buchbar -> erst nach Absprache/Freigabe
                $kind = 'frei';
                if (($s[2] ?? 1) == 2) {
                    $kind = $fiOnRequest ? 'anfrage' : 'absprache';
                }
                $out[] = [
                    'date'    => $ds,
                    'start'   => $this->min2str($start),
                    'end'     => $this->min2str($end),
                    'minutes' => $end - $start,
                    'kind'    => $kind,
                ];
                if (count($out) >= $want) {
                    break;
                }
            }
        }

        return $this->json(['slots' => $out, 'days' => $maxDays]);
    }

    /** Schnittmenge zweier Intervall-Listen (Minuten). */
    private function intersect(array $a, array $b): array
    {
        $result = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                $start = max($x[0], $y[0]);
                $end   = min($x[1], $y[1]);
                if ($end > $start) {
                    $result[] = [$start, $end];
                }
            }
        }
        usort($result, fn ($p, $q) => $p[0] <=> $q[0]);

        return $result;
    }

    /**
     * Schnittmenge zweier Intervall-Listen, die jeweils einen Typ tragen
     * ([start,end,typ] mit 1=frei, 2=auf Anfrage). Der "auf Anfrage"-Typ (2)
     * dominiert: ist eine der beiden Seiten nur auf Anfrage verfuegbar, ist der
     * resultierende Termin ebenfalls nur auf Anfrage buchbar.
     */
    private function intersectWithType(array $a, array $b): array
    {
        $result = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                $start = max($x[0], $y[0]);
                $end   = min($x[1], $y[1]);
                if ($end > $start) {
                    $typ = (($x[2] ?? 1) == 2 || ($y[2] ?? 1) == 2) ? 2 : 1;
                    $result[] = [$start, $end, $typ];
                }
            }
        }
        usort($result, fn ($p, $q) => $p[0] <=> $q[0]);

        return $result;
    }

    /** Intervall-Liste -> Ausgabeformat. */
    private function slotsOut(array $slots, bool $withMinutes = false): array
    {
        $out = [];
        foreach ($slots as $s) {
            $slot = ['start' => $this->min2str($s[0]), 'end' => $this->min2str($s[1])];
            if ($withMinutes) {
                $slot['minutes'] = $s[1] - $s[0];
            }
            $out[] = $slot;
        }

        return $out;
    }

    private function min2str(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }
}
