<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF-Schutz fuer die klassischen /modern-Formulare.
 *
 * Diese Formulare sind server-gerenderte Twig-POSTs OHNE CSRF-Token. Da die
 * Session per Cookie authentifiziert ist und der Cookie in prod auf
 * SameSite=None steht (Joomla-iframe), wuerde ein fremder Tab den Auth-Cookie
 * mitsenden -> CSRF. Wir pruefen daher host-basiert den Absender (Origin,
 * ersatzweise Referer) gegen den Ziel-Host plus die Allowlist – exakt analog
 * zu ApiController::denyCrossOrigin, das die /api-Schreibzugriffe schuetzt.
 *
 * Greift fuer zustandsaendernde Methoden (POST/PUT/PATCH/DELETE) auf /modern
 * sowie auf das Login-FORMULAR (Route app_login, /login) – Schutz gegen
 * Login-CSRF, ohne Token in den drei Login-Templates pflegen zu muessen.
 *
 * Bewusst NICHT erfasst: /loginwithcredentials und /api/login – das sind die
 * (absichtlich cross-site) Joomla-SSO-Pfade; sie werden separat behandelt.
 */
class CsrfOriginSubscriber implements EventSubscriberInterface
{
    /** @var string[] */
    private array $allowedOrigins;
    /** @var string[] */
    private array $allowedHosts;

    public function __construct(string $allowedOrigins)
    {
        $this->allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOrigins))));
        $this->allowedHosts   = array_values(array_filter(array_map(
            static fn ($o) => parse_url($o, PHP_URL_HOST),
            $this->allowedOrigins
        )));
    }

    public static function getSubscribedEvents(): array
    {
        // Nach dem RouterListener (Prio 32, damit _route gesetzt ist), aber VOR
        // der Firewall (Prio 8) – sonst haette der LoginFormAuthenticator den
        // Login-POST schon verarbeitet, bevor wir ihn ablehnen koennen.
        return [KernelEvents::REQUEST => ['onRequest', 10]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $req = $event->getRequest();

        if (!in_array($req->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        // /modern-Formulare + das Login-Formular (Route app_login). NICHT
        // /loginwithcredentials oder /api/login (Joomla-SSO, eigene Behandlung).
        $guarded = str_starts_with($req->getPathInfo(), '/modern')
                || $req->attributes->get('_route') === 'app_login';
        if (!$guarded) {
            return;
        }
        if (!$this->isSameOrigin($req)) {
            throw new AccessDeniedHttpException('CSRF: Cross-Origin-Anfrage abgelehnt.');
        }
    }

    /** Host-basierter Origin/Referer-Abgleich (analog ApiController::denyCrossOrigin). */
    private function isSameOrigin(Request $req): bool
    {
        $expectedHost = $req->getHost();

        $origin = $req->headers->get('Origin');
        if ($origin !== null && $origin !== '') {
            return in_array($origin, $this->allowedOrigins, true)
                || parse_url($origin, PHP_URL_HOST) === $expectedHost;
        }

        $referer = $req->headers->get('Referer');
        if ($referer !== null && $referer !== '') {
            $rHost = parse_url($referer, PHP_URL_HOST);
            return $rHost === $expectedHost || in_array($rHost, $this->allowedHosts, true);
        }

        // Browser senden bei Schreibzugriffen einen Origin – fehlt beides, ablehnen.
        return false;
    }
}
