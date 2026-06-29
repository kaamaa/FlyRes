<?php

namespace App\Controller\Api;

use App\Entity\FresAccounts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gemeinsame Basis fuer alle JSON-API-Controller der iPhone-PWA.
 *
 * Die /api/*-Pfade sind in security.yaml als PUBLIC_ACCESS markiert, damit der
 * Controller bei fehlender Anmeldung sauberes JSON (401) zurueckgeben kann
 * statt eines HTML-Redirects auf die Login-Seite. Die eigentliche
 * Zugriffspruefung passiert hier programmatisch.
 */
abstract class ApiController extends AbstractController
{
    /**
     * Liefert den angemeldeten Piloten – oder eine fertige JSON-Fehlerantwort,
     * die der Aufrufer direkt zurueckgeben kann:
     *
     *   $user = $this->requirePilot();
     *   if ($user instanceof JsonResponse) { return $user; }
     */
    protected function requirePilot(): FresAccounts|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof FresAccounts) {
            return $this->json(['error' => 'not_authenticated'], 401);
        }
        if (!$this->isGranted('ROLE_PILOT')) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        return $user;
    }

    /**
     * CSRF-Schutz fuer zustandsaendernde Requests (POST/PATCH/PUT/DELETE).
     *
     * Da die PWA ueber das Session-Cookie authentifiziert ist, wird der
     * Absender-Host gegen den Ziel-Host geprueft (Origin, ersatzweise Referer).
     * Browser senden bei nicht-einfachen Requests immer einen Origin-Header;
     * ein fremder Host => Ablehnung. Bewusst host-basiert (nicht scheme/port),
     * um hinter Reverse-Proxies robust zu bleiben.
     *
     * Rueckgabe: null = ok, sonst eine fertige 403-JSON-Antwort.
     */
    protected function denyCrossOrigin(Request $request): ?JsonResponse
    {
        $expectedHost = $request->getHost();
        // Vertrauenswuerdige Fremd-Origins (z. B. Joomla-Einbettung) zusaetzlich erlauben.
        $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', (string) $this->getParameter('app.cors_allowed_origins')))));
        $allowedHosts   = array_filter(array_map(static fn ($o) => parse_url($o, PHP_URL_HOST), $allowedOrigins));

        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== '') {
            if (in_array($origin, $allowedOrigins, true)) {
                return null;
            }
            return parse_url($origin, PHP_URL_HOST) === $expectedHost
                ? null
                : $this->json(['error' => 'cross_origin_denied'], 403);
        }

        $referer = $request->headers->get('Referer');
        if ($referer !== null && $referer !== '') {
            $rHost = parse_url($referer, PHP_URL_HOST);
            return ($rHost === $expectedHost || in_array($rHost, $allowedHosts, true))
                ? null
                : $this->json(['error' => 'cross_origin_denied'], 403);
        }

        // Ein Browser sendet bei Schreibzugriffen einen Origin – fehlt beides, ablehnen.
        return $this->json(['error' => 'origin_required'], 403);
    }
}
