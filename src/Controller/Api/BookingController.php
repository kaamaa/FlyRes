<?php

namespace App\Controller\Api;

use App\Entities\Bookings;
use App\Entities\FIAvailability;
use App\Entities\FlightPurposes;
use App\Entities\Licenses;
use App\Entities\Planes;
use App\Entities\Users;
use App\Controller\MailParamsTrait;
use App\Entity\FresBooking;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Reservierungen lesen (M2) und schreiben (M4).
 *
 *   GET    /api/bookings?view=&aircraft=&fi=&pilot=   – Liste (Meine / Alle)
 *   GET    /api/bookings/{id}                         – Detail
 *   POST   /api/bookings                              – neu reservieren
 *   PATCH  /api/bookings/{id}                         – aendern
 *   DELETE /api/bookings/{id}                         – loeschen
 *
 * Schreiboperationen verwenden exakt dieselbe Validierungskette und denselben
 * Mailversand wie EditBookingController::SaveAction bzw.
 * ViewBookingDetailsController::DeleteAction – ueber die bestehenden Helfer in
 * App\Entities. Hier wird nur die Orchestrierung ohne Formular/Twig
 * nachgebildet, keine Fachlogik dupliziert.
 */
class BookingController extends ApiController
{
    use MailParamsTrait;

    /** Erlaubte "view"-Werte -> bestehende GeneralView-Kommandos (Whitelist gegen internes die()). */
    private const VIEW_MAP = [
        'mine'         => 'own_fi',          // eigene + als Fluglehrer zugewiesene (kommende)
        'mine_history' => 'own_fi_history',  // eigene + als Fluglehrer zugewiesene (alle/vergangene)
        'today'        => 'today',
        'tomorrow'     => 'tomorrow',
        'thisweek'     => 'thisweek',
        'weekafter'    => 'weekafter',
        'thisweekend'  => 'thisweekend',
        'nextweekend'  => 'nextweekend',
        'thismonth'    => 'thismonth',
        'all'          => 'date',
    ];

    // ---------------------------------------------------------------- Lesen

    /** GET /api/bookings */
    public function list(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $view = $request->query->get('view', 'all');
        if (!isset(self::VIEW_MAP[$view])) {
            return $this->json(['error' => 'invalid_view', 'allowed' => array_keys(self::VIEW_MAP)], 400);
        }
        $command  = self::VIEW_MAP[$view];
        $clientid = $user->getClientid();

        $rows = Bookings::GetBookingsForGeneralView($em, $command, $clientid, $user->getId());

        $aircraftId = $request->query->getInt('aircraft', 0);
        $fiId       = $request->query->getInt('fi', 0);
        $pilotId    = $request->query->getInt('pilot', 0);

        $aircraftName = $aircraftId ? Planes::GetPlaneNameAndKennung($em, $clientid, $aircraftId) : null;
        $fiName       = $fiId ? Users::GetUserName($em, $clientid, $fiId) : null;

        $result = [];
        foreach ($rows as $row) {
            if ($aircraftName !== null && $row['flugzeug'] !== $aircraftName) {
                continue;
            }
            if ($fiName !== null && $row['flightinstructor'] !== $fiName) {
                continue;
            }
            if ($pilotId && (int) $row['userid'] !== $pilotId) {
                continue;
            }

            $result[] = [
                'id'          => $row['bookingid'],
                'date'        => $row['date'],
                'start'       => $row['start'],
                'end'         => $row['end'],
                'aircraft'    => $row['flugzeug'],
                'pilotId'     => (int) $row['userid'],
                'pilot'       => $row['user'],
                'instructor'  => $row['flightinstructor'] ?: null,
                'purpose'     => $row['flightpurpose'],
                'isTraining'  => (bool) $row['isflighttraining'],
                'description' => $row['description'],
            ];
        }

        return $this->json($result);
    }

    /** GET /api/bookings/{id} */
    public function detail(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $d = Bookings::GetBookingDetails($em, $user->getClientid(), $id, $user);
        if ($d === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        $out = $this->serializeDetail($d);
        // Rohwerte (IDs/Zeiten) zum Vorbefuellen des Bearbeiten-Formulars.
        $entity = Bookings::GetBookingObject($em, $user->getClientid(), $id);
        if ($entity) {
            $out['edit'] = [
                'aircraftId'       => $entity->getAircraftid(),
                'flightinstructor' => $entity->getFlightinstructor(),
                'flightpurposeId'  => $entity->getFlightpurposeid(),
                'airfieldId'       => $entity->getAirfieldid(),
                'date'             => $entity->getItemstart()->format('Y-m-d'),
                'endDate'          => $entity->getItemstop()->format('Y-m-d'),
                'startTime'        => $entity->getItemstart()->format('H:i'),
                'endTime'          => $entity->getItemstop()->format('H:i'),
                'description'      => $entity->getDescription(),
            ];
        }

        return $this->json($out);
    }

    // -------------------------------------------------------------- Schreiben

    /** POST /api/bookings */
    public function create(Request $request, MailerInterface $mailer, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($csrf = $this->denyCrossOrigin($request)) {
            return $csrf;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        $booking = new FresBooking();
        $booking->setClientid($user->getClientid());
        $booking->setCreatedDate(new \DateTime('now'));
        $booking->setChangeddate(new \DateTime('now'));
        $booking->setStatus(0);
        // Ersteller: standardmaessig der angemeldete Nutzer; Override nur fuer Admins.
        $createdFor = ($this->isGranted('ROLE_ADMIN') && !empty($data['createdForUserId']))
            ? (int) $data['createdForUserId']
            : $user->getId();
        $booking->setCreatedbyuserid($createdFor);

        $errors = $this->applyAndValidate($booking, $data, $user, $em, true);
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], 422);
        }

        $em->persist($booking);
        $em->flush();
        $this->sendBookingMail($em, $user, $mailer, $booking, null, $this->clientSource($request));

        $d = Bookings::GetBookingDetails($em, $user->getClientid(), $booking->getId(), $user);

        return $this->json($this->serializeDetail($d), 201);
    }

    /** PATCH /api/bookings/{id} */
    public function update(int $id, Request $request, MailerInterface $mailer, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($csrf = $this->denyCrossOrigin($request)) {
            return $csrf;
        }

        $booking = Bookings::GetBookingObject($em, $user->getClientid(), $id);
        if (!$booking) {
            return $this->json(['error' => 'not_found'], 404);
        }
        if (!Bookings::IsAllowedtoChangeBooking($em, $user, $booking)) {
            return $this->json(['error' => 'forbidden'], 403);
        }
        // Vergangene Buchungen (Ende mehr als eine Woche her) nicht mehr bearbeitbar.
        if (!Bookings::IsBookingDateEditable($booking)) {
            return $this->json(['error' => 'too_old', 'errors' => ['Buchungen, deren Ende mehr als eine Woche zurückliegt, können nicht mehr bearbeitet werden.']], 422);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        // booking_old fuer den Mailvergleich (Aenderungs-Mail)
        $bookingOld = clone $booking;
        $booking->setChangeddate(new \DateTime('now'));
        $booking->setChangedbyuserid($user->getId());

        $errors = $this->applyAndValidate($booking, $data, $user, $em, false);
        if (!empty($errors)) {
            return $this->json(['errors' => $errors], 422);
        }

        $em->persist($booking);
        $em->flush();
        $this->sendBookingMail($em, $user, $mailer, $booking, $bookingOld, $this->clientSource($request));

        $d = Bookings::GetBookingDetails($em, $user->getClientid(), $booking->getId(), $user);

        return $this->json($this->serializeDetail($d));
    }

    /** DELETE /api/bookings/{id} */
    public function delete(int $id, Request $request, MailerInterface $mailer, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->requirePilot();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($csrf = $this->denyCrossOrigin($request)) {
            return $csrf;
        }

        $booking = Bookings::GetBookingObject($em, $user->getClientid(), $id);
        if (!$booking) {
            return $this->json(['error' => 'not_found'], 404);
        }
        if (!Bookings::IsAllowedtoChangeBooking($em, $user, $booking)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        // Erst Stornierungs-Mail (mit den noch vorhandenen Daten), dann loeschen – wie DeleteAction.
        $this->sendBookingMail($em, $user, $mailer, null, $booking, $this->clientSource($request));
        Bookings::DeleteBooking($em, $user->getClientid(), $id);

        return $this->json(['success' => true]);
    }

    // ---------------------------------------------------------------- intern

    /**
     * Uebernimmt die JSON-Daten in die Buchung und fuehrt dieselbe
     * Validierungskette wie SaveAction aus. Gibt die Liste der Fehlertexte
     * zurueck (leer = gueltig).
     */
    private function applyAndValidate(FresBooking $booking, array $data, $user, EntityManagerInterface $em, bool $isNew): array
    {
        $clientid = $booking->getClientid();
        $errors = [];

        // Datumswerte zuerst – ohne gueltige Daten sind die Folgepruefungen nicht moeglich.
        $start = $this->parseDt((string) ($data['start'] ?? ''));
        $end   = $this->parseDt((string) ($data['end'] ?? ''));
        if (!$start) {
            $errors[] = 'Das Startdatum ist kein gültiges Datum';
        }
        if (!$end) {
            $errors[] = 'Das Enddatum ist kein gültiges Datum';
        }
        if ($errors) {
            return $errors;
        }

        $booking->setAircraftid((int) ($data['aircraftId'] ?? 0));
        if (array_key_exists('airfieldId', $data)) {
            $booking->setAirfieldid($data['airfieldId'] !== null ? (int) $data['airfieldId'] : null);
        }
        $booking->setFlightpurposeid((int) ($data['flightpurposeId'] ?? 0) ?: null);
        $fi = !empty($data['flightinstructor']) ? (int) $data['flightinstructor'] : null;
        $booking->setFlightinstructor($fi);
        $booking->setItemstart($start);
        $booking->setItemstop($end);
        if (array_key_exists('description', $data)) {
            $booking->setDescription((string) $data['description']);
        }

        // Interne Empfaenger werden als User-IDs uebergeben und – wie in SaveAction –
        // erst beim Speichern in Mailadressen aufgeloest.
        if (array_key_exists('emailInternUserIds', $data)) {
            $ids = is_array($data['emailInternUserIds']) ? $data['emailInternUserIds'] : [];
            $booking->setEmailinfoi(Users::GetAllMailsadressesByUserlist($em, $ids, $clientid));
        } elseif ($isNew) {
            $booking->setEmailinfoi('');
        }
        if (array_key_exists('emailInfoExtern', $data)) {
            $booking->setEmailinfoe((string) $data['emailInfoExtern']);
        } elseif ($isNew) {
            $booking->setEmailinfoe('');
        }

        // --- Validierungskette, identisch zu SaveAction ---
        $e = Bookings::IsPlaneAvailable($em, $booking);
        if (!empty($e)) {
            $errors[] = $e;
        }
        $e = Bookings::IsFlightinstructorNotBooked($em, $booking);
        if (!empty($e)) {
            $errors[] = $e;
        }
        $e = FIAvailability::IsFlightinstructorAvailable($em, $booking, $user);
        if ($e !== '') {
            $errors[] = $e;
        }
        if (!Users::IsMailListValid($booking->getEmailinfoe())) {
            $errors[] = 'Mindestens eine externe Mailadresse ist keine korrekte Mailadresse';
        }

        $isSchulung = FlightPurposes::IsSchulung($booking->getflightpurposeid());
        if ($booking->getItemstart() >= $booking->getItemstop()) {
            $errors[] = 'Das Ende der Reservierung muss später als der Start sein';
        }
        if ($isSchulung && $booking->getFlightinstructor() === null) {
            $errors[] = 'Für Schulflüge muss ein Fluglehrer ausgewählt werden';
        }
        if ($booking->getAircraftid() == 0) {
            $errors[] = 'Es muss ein Flugzeug ausgewählt werden';
        }

        $licenceError = Licenses::CheckIfLicencesAreValid(
            $em,
            $clientid,
            $booking->getCreatedbyuserid(),
            Planes::GetAircraftTypeForAircraft($em, $booking->getAircraftid(), $clientid),
            $booking->getItemstart()->format('Y-m-d'),
            $isSchulung
        );
        if ($licenceError != null) {
            $errors[] = $licenceError;
        }

        if (!$this->isGranted('ROLE_ADMIN')) {
            $advancebookingerror = Planes::CheckIfBookingIsInAdvanceRange($em, $clientid, $booking->getAircraftid(), $booking->getItemstart());
            if ($advancebookingerror != '') {
                $errors[] = $advancebookingerror;
            }
        }

        return $errors;
    }

    private function sendBookingMail(EntityManagerInterface $em, $user, MailerInterface $mailer, ?FresBooking $new, ?FresBooking $old, string $source = 'web'): void
    {
        $parameter = $this->mailParams($source);
        $twig = $this->container->get('twig');
        Bookings::SendBookingsInfoMail($em, $user, $twig, $new, $old, $mailer, $parameter);
    }

    /**
     * Herkunft des Requests fuer die Mail-Kennzeichnung: 'mobile' nur, wenn das
     * Frontend den Header X-FlyRes-Client: mobile setzt (PWA). Sonst 'web'
     * (modernes Web-Frontend wie klassische Oberflaeche).
     */
    private function clientSource(Request $request): string
    {
        return strtolower((string) $request->headers->get('X-FlyRes-Client')) === 'mobile' ? 'mobile' : 'web';
    }

    /** Akzeptiert "Y-m-d H:i" und ISO-"Y-m-dTH:i" (optional mit Sekunden). */
    private function parseDt(string $s): ?\DateTime
    {
        $s = trim($s);
        foreach (['Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $s);
            if ($d && $d->format($fmt) === $s) {
                return $d;
            }
        }

        return null;
    }

    /** Detail-Array von GetBookingDetails -> sauberes API-JSON. */
    private function serializeDetail(array $d): array
    {
        return [
            'id'              => $d['id'],
            'aircraft'        => $d['flugzeug'],
            'instructor'      => $d['flightinstructor'] ?: null,
            'airfield'        => $d['airfield'],
            'purpose'         => $d['flightpurpose'],
            'start'           => $d['start'],
            'end'             => $d['end'],
            'reservedFor'     => $d['ReservedForUser'],
            'reservedAt'      => $d['ReservedAt'],
            'changedBy'       => $d['ChangedFromUser'] ?: null,
            'changedAt'       => $d['ChangedAt'] ?: null,
            'phone'           => [
                'home'   => $d['telhome'],
                'office' => $d['teloffice'],
                'mobile' => $d['telmobile'],
            ],
            'email'           => $d['mail'],
            'description'     => $d['description'],
            'emailInfoIntern' => $d['EmailInfoIntern'],
            'emailInfoExtern' => $d['EmailInfoExtern'],
            'canEdit'         => (bool) $d['modify'],
            // Bearbeiten zusaetzlich datumsabhaengig (Ende max. 1 Woche her);
            // Storno/Loeschen richtet sich weiter nach canEdit.
            'canEditDate'     => (bool) ($d['editable'] ?? $d['modify']),
        ];
    }
}
