<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS fuer vertrauenswuerdige Fremd-Origins (z. B. die Joomla-Einbettung der
 * FlyRes-Subdomain in einem Cross-Site-iframe).
 *
 * - Spiegelt NUR Origins aus der Allowlist zurueck (niemals beliebige Origins
 *   und nie "*" zusammen mit Credentials).
 * - Erlaubt Credentials, damit das Session-/RememberMe-Cookie im iframe greift.
 * - Beantwortet OPTIONS-Preflights direkt (vor Routing/Security).
 *
 * Nur fuer /api-Pfade (dort feuert die PWA/Joomla ihre fetch()-Aufrufe).
 */
class CorsSubscriber implements EventSubscriberInterface
{
    /** @var string[] */
    private array $allowed;

    public function __construct(string $allowedOrigins)
    {
        $this->allowed = array_values(array_filter(array_map('trim', explode(',', $allowedOrigins))));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // sehr frueh: Preflight beantworten, bevor Routing/Security greift
            KernelEvents::REQUEST  => ['onRequest', 9999],
            KernelEvents::RESPONSE => ['onResponse', -9999],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $req = $event->getRequest();
        if ($req->getMethod() !== 'OPTIONS' || !$this->isApi($req)) {
            return;
        }
        $origin = $req->headers->get('Origin');
        if (!$this->isAllowed($origin)) {
            return;
        }
        // Preflight sofort + abschliessend beantworten
        $resp = new Response('', 204);
        $this->addHeaders($resp, $origin, $req);
        $event->setResponse($resp);
        $event->stopPropagation();
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $req = $event->getRequest();
        if (!$this->isApi($req)) {
            return;
        }
        $origin = $req->headers->get('Origin');
        if (!$this->isAllowed($origin)) {
            return;
        }
        $this->addHeaders($event->getResponse(), $origin, $req);
    }

    private function isApi(Request $req): bool
    {
        return str_starts_with($req->getPathInfo(), '/api');
    }

    private function isAllowed(?string $origin): bool
    {
        return $origin !== null && $origin !== '' && in_array($origin, $this->allowed, true);
    }

    private function addHeaders(Response $resp, string $origin, Request $req): void
    {
        $h = $resp->headers;
        $h->set('Access-Control-Allow-Origin', $origin);
        $h->set('Access-Control-Allow-Credentials', 'true');
        $h->set('Vary', 'Origin', false);   // anhaengen, vorhandenes Vary nicht ueberschreiben
        $h->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
        $reqHeaders = $req->headers->get('Access-Control-Request-Headers');
        $h->set('Access-Control-Allow-Headers', $reqHeaders ?: 'Content-Type, Authorization, X-Requested-With');
        $h->set('Access-Control-Max-Age', '600');
    }
}
