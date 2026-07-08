<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Schreibt Sicherheits-/Audit-Ereignisse in den eigenen Monolog-Kanal "audit"
 * (rotierende Tagesdatei var/log/audit-YYYY-MM-DD.log, ein JSON-Objekt je Zeile).
 * Jede Zeile wird um aktuellen Nutzer, Mandant und IP angereichert. Bewusst
 * schlank und ohne DB, damit es auch bei DB-Problemen funktioniert und die
 * Diagnose-Seite es einfach auslesen kann.
 */
class AuditLogger
{
    public function __construct(
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    /**
     * @param string      $event   Ereignis-Schluessel, z. B. "login.failure", "booking.cancel".
     * @param array       $details Zusaetzliche Felder (Objekt-ID, Ziel-Nutzer, alt/neu …).
     * @param string|null $actor   Ueberschreibt den Akteur (z. B. Login-Fehlversuch,
     *                             wenn noch niemand angemeldet ist).
     */
    public function log(string $event, array $details = [], ?string $actor = null): void
    {
        $user = $this->security->getUser();
        $req  = $this->requestStack->getCurrentRequest();

        $base = [
            'actor'    => $actor ?? $user?->getUserIdentifier(),
            'clientid' => ($user && method_exists($user, 'getClientid')) ? $user->getClientid() : null,
            'ip'       => $req?->getClientIp(),
        ];

        // Details duerfen Basisfelder ueberschreiben (z. B. clientid beim Login,
        // wenn der Security-Kontext noch nicht gesetzt ist).
        $this->logger->info($event, array_merge($base, $details));
    }
}
