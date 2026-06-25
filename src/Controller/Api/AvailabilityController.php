<?php

namespace App\Controller\Api;

use App\Entities\Bookings;
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
 * steckt in den bestehenden, oeffentlichen Methoden
 * Bookings::GetAllAvailablePlanesForADate() / GetAllAvailableFIsForADate().
 * Diese liefern einen kompakten HTML-String mit Zeitbereichen ("G:i-G:i"),
 * den wir hier in strukturierte Slots ueberfuehren. So bleibt die komplette
 * Fachlogik an einer Stelle und der Bestandscode unveraendert.
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

        $aircraftId = $request->query->getInt('aircraft', 0);
        $fiId       = $request->query->getInt('fi', 0);

        // --- Tagesfenster (sonnenstandsbasiert, wie im Hauptprogramm) ---
        [$dsH, $dsM] = TimeFunctions::GetDayStart($date);
        [$deH, $deM] = TimeFunctions::GetDayEnd($date);
        $dayStart = $dsH * 60 + $dsM;
        $dayEnd   = $deH * 60 + $deM;

        // --- Verfuegbarkeiten der bestehenden Logik holen und parsen ---
        $planeBlocks = $this->parseBlocks(Bookings::GetAllAvailablePlanesForADate($em, $clientid, $date));
        $fiBlocks    = $this->parseBlocks(Bookings::GetAllAvailableFIsForADate($em, $clientid, $date));

        // --- Flugzeug: freie Luecken fuer das gewaehlte Flugzeug ---
        $aircraftFree = null;
        if ($aircraftId) {
            $plane = Planes::GetPlaneObject($em, $clientid, $aircraftId);
            $kennung = $plane ? $plane->getKennung() : null;
            $aircraftFree = ($kennung !== null) ? ($planeBlocks[$kennung] ?? []) : [];
        }

        // --- Fluglehrer: Verfuegbarkeitsfenster fuer den gewaehlten FI ---
        $instructorFree = null;
        if ($fiId) {
            $fi = Users::GetUserObject($em, $clientid, $fiId);
            $fiName = $fi ? ($fi->getFirstname() . ' ' . $fi->getLastname()) : null;
            $instructorFree = ($fiName !== null) ? ($fiBlocks[$fiName] ?? []) : [];
        }

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
            'instructorId'   => $fiId ?: null,
            'instructorFree' => $instructorFree === null ? null : $this->slotsOut($instructorFree),
            'freeSlots'      => $this->slotsOut($slots, true),
        ]);
    }

    /**
     * Zerlegt den HTML-String der Verfuegbarkeitsmethoden in
     * [ label => [ [startMin,endMin], ... ] ].
     * Bloecke sind durch <br> getrennt; das Label steht im ersten <b>...</b>,
     * Zeitbereiche im Format "G:i-G:i".
     */
    private function parseBlocks(string $html): array
    {
        $out = [];
        foreach (explode('<br>', $html) as $block) {
            if (trim($block) === '') {
                continue;
            }
            if (!preg_match('/<b>(.*?)<\/b>/s', $block, $m)) {
                continue;
            }
            $label = trim($m[1]);
            preg_match_all('/(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})/', $block, $ranges, PREG_SET_ORDER);
            $list = [];
            foreach ($ranges as $r) {
                $start = (int) $r[1] * 60 + (int) $r[2];
                $end   = (int) $r[3] * 60 + (int) $r[4];
                if ($end > $start) {
                    $list[] = [$start, $end];
                }
            }
            // Mehrere Bloecke mit gleichem Label zusammenfuehren (Sicherheit)
            $out[$label] = array_merge($out[$label] ?? [], $list);
        }

        return $out;
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
