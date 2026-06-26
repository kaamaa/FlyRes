# Bearer-Token-Authentifizierung für FlyRes-API

**Datum:** 2026-06-26
**Branch:** `feature/bearer-token-auth`
**Status:** Spec — bereit für Implementierungsplan

## Motivation

Die FlyRes-Web-API (`/api/*`) authentifiziert aktuell ausschließlich per PHP-Session-Cookie. Für die geplante native FlutterFlow-App ist Cookie-Auth unzuverlässig (FlutterFlow-API-Calls behandeln Cookies nicht wie Browser). Diese Spec ergänzt eine zweite Authentifizierungsmethode per Bearer-Token, **parallel** zur bestehenden Cookie-Auth — die PWA bleibt unverändert funktional.

## Nicht-Ziele

- Ersatz der Cookie-Auth (PWA und Joomla-Integration bleiben)
- JWT oder Stateless-Auth
- Token-Rotation/Refresh-Tokens (v1 nutzt langlebige Tokens, App-Store-konform)
- Rollen-/Scope-Beschränkung pro Token (Token = volle User-Rechte)
- Migration auf Doctrine-Migrations-Framework

## Architektur

```
PWA  ─ POST /api/login    ─►  Session-Cookie  ─►  LoginFormAuthenticator   (unverändert)
App  ─ POST /api/tokens   ─►  Bearer-Token    ─►  BearerTokenAuthenticator (neu)
                                                   │
                                                   ▼
                                              FRes_api_tokens (neue Tabelle)
```

Beide Authenticatoren leben in derselben `main`-Firewall. Der neue `BearerTokenAuthenticator` läuft **vor** `LoginFormAuthenticator`; sein `supports()` triggert ausschließlich bei vorhandenem `Authorization: Bearer …`-Header. Dank `lazy: true` wird bei Bearer-Requests keine Session geladen — also kein Performance-Overhead und kein Set-Cookie an die App.

## Datenmodell

Neue Tabelle `FRes_api_tokens`:

| Spalte | Typ | Constraints | Zweck |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| `user_id` | INT UNSIGNED | NOT NULL, FK → `FRes_accounts.id`, ON DELETE CASCADE | Besitzer |
| `token_hash` | CHAR(64) | NOT NULL, UNIQUE | SHA-256-Hash des Klartext-Tokens |
| `device_name` | VARCHAR(100) | NULL | frei wählbar bei Login (z. B. „iPhone von Oliver") |
| `user_agent` | VARCHAR(255) | NULL | User-Agent zum Erstellungszeitpunkt (Audit) |
| `last_ip` | VARCHAR(45) | NULL | IP der letzten Nutzung (IPv4/IPv6) |
| `created_at` | DATETIME | NOT NULL | |
| `last_used_at` | DATETIME | NULL | wird bei jedem Bearer-Request aktualisiert |
| `expires_at` | DATETIME | NULL | NULL = unbegrenzt (Default); reserviert für künftige Policy |

Index: `(token_hash)` (durch UNIQUE), `(user_id, last_used_at)` für Listings.

**Klartext-Tokens werden niemals gespeichert** — nur SHA-256-Hash. SHA-256 (ohne Salt) ist ausreichend, da der Token aus 256 Bit Zufall besteht — nichts zu „brute-forcen", der Hash schützt nur gegen DB-Leak.

## Token-Format

```
flyres_<base64url(32 zufällige Bytes)>
```

Beispiel: `flyres_xJ9kQm8N2pL5vR7tY3wF6cH1aB4dE8gK0mP9sU2nT5rW`

- **Prefix `flyres_`**: erkennbar in Logs, scannbar durch Secret-Scanner (analog `ghp_`, `sk_live_`)
- **32 Bytes Entropie**: ~256 Bit, kollisionsfrei
- **base64url** (kein `+`/`/`): URL-safe, keine Escaping-Probleme
- Klartext-Länge: 50 Zeichen (7 Prefix + 43 Base64), passt komfortabel in HTTP-Header

## Endpunkte

### `POST /api/tokens` — Token ausstellen (Login)

**Request:**
```json
{
  "username": "oliver",
  "password": "...",
  "client": "ASW",
  "device_name": "iPhone von Oliver"
}
```

**Response 200:**
```json
{
  "token": "flyres_xJ9k...",
  "user": {
    "id": 42,
    "username": "oliver",
    "firstname": "Oliver",
    "lastname": "Scharfenberger",
    "email": "oliver@…",
    "clientid": 1,
    "roles": ["ROLE_PILOT"],
    "isInstructor": false,
    "isAdmin": false
  }
}
```

**Errors:**
- `400 missing_credentials` — Username/Passwort leer
- `401 invalid_credentials` — falsche Zugangsdaten
- `403 account_locked` — User gelöscht oder gesperrt
- `429 rate_limited` — > 5 Versuche/min von dieser IP

Wiederverwendet die Validierungs-Logik aus `LoginController::apiLogin()` (selbe Fehlercodes, gleiche User-Lookup-Funktion).

### `DELETE /api/tokens/current` — Aktuelles Token widerrufen (Logout)

Header: `Authorization: Bearer flyres_…`

**Response 204** (No Content) — Token wird aus DB gelöscht, ab sofort ungültig.

### `GET /api/tokens` — Eigene aktive Tokens auflisten

**Response 200:**
```json
[
  {
    "id": 7,
    "device_name": "iPhone von Oliver",
    "created_at": "2026-06-20T14:32:00Z",
    "last_used_at": "2026-06-26T09:15:00Z",
    "last_ip": "84.12.…",
    "is_current": true
  },
  { "id": 8, "device_name": "iPad", "is_current": false, ... }
]
```

**Klartext-Token wird hier nie zurückgegeben** — der ist nach `POST` für immer weg.

### `DELETE /api/tokens/{id}` — Anderes Token widerrufen

Für „Andere Geräte abmelden". 404 wenn das Token nicht dem aktuellen User gehört.

## Authenticator-Implementierung

Neue Klasse `App\Security\BearerTokenAuthenticator extends AbstractAuthenticator`:

```php
public function supports(Request $request): ?bool
{
    return $request->headers->has('Authorization')
        && str_starts_with($request->headers->get('Authorization'), 'Bearer ');
}

public function authenticate(Request $request): Passport
{
    $rawToken = substr($request->headers->get('Authorization'), 7);
    $hash = hash('sha256', $rawToken);

    return new SelfValidatingPassport(
        new UserBadge($hash, function (string $hash) {
            $apiToken = $this->repo->findOneBy(['tokenHash' => $hash]);
            if (!$apiToken) throw new UserNotFoundException();
            // last_used_at + last_ip update (async optional)
            return $apiToken->getUser();
        })
    );
}
```

`SelfValidatingPassport` weil das Token selbst die Credential ist — kein zusätzlicher Password-Check.

`onAuthenticationFailure()` → `JsonResponse(['error' => 'invalid_token'], 401)`.

## Sicherheit

- **Speicherung:** Nur SHA-256-Hash in DB, Klartext nie persistiert
- **Übertragung:** Token nur einmal im `POST /api/tokens`-Response → Client muss sicher speichern (FlutterFlow: `flutter_secure_storage`)
- **Rate-Limit:** `POST /api/tokens` → 5/min pro IP via Symfony `framework.rate_limiter`
- **Revoke:** sofort wirksam (DB-Lookup pro Request, kein Cache in v1)
- **Audit-Trail:** `created_at`, `last_used_at`, `last_ip`, `user_agent` → bei Vorfall nachvollziehbar
- **HTTPS:** wird im bestehenden Setup ohnehin erzwungen (Apache-Config); ohne TLS wäre Bearer-Auth genauso unsicher wie Cookie-Auth

## Symfony-Konfiguration

`config/packages/security.yaml`:

```yaml
firewalls:
    main:
        custom_authenticators:
            - App\Security\BearerTokenAuthenticator   # <- NEU, vor LoginFormAuthenticator
            - App\Security\LoginFormAuthenticator
        # ... Rest unverändert

access_control:
    # ... bestehende Einträge
    - { path: ^/api/tokens$, methods: [POST], roles: PUBLIC_ACCESS }
    # DELETE/GET /api/tokens und /api/tokens/{id} brauchen Auth → keine PUBLIC_ACCESS-Regel
```

Neuer Eintrag in `config/packages/rate_limiter.yaml`:

```yaml
framework:
    rate_limiter:
        api_token_login:
            policy: 'token_bucket'
            limit: 5
            rate: { interval: '1 minute' }
```

## Was sich **nicht** ändert

- `LoginController::apiLogin()` / `apiLogout()` / `apiVerify()` — komplett unverändert
- Alle bestehenden `/api/*`-Endpunkte (bookings, availability, …) — akzeptieren ab sofort beides ohne Code-Änderung, da der Authenticator vor dem Controller läuft
- `LoginFormAuthenticator`, `FlyResAuthenticator`, `LoginType` — unangetastet
- PWA-Frontend (`frontend/src/api.js`) — unverändert
- Bestehende Tabellen (`FRes_accounts`, `FRes_booking`, …) — unverändert

## Komponenten-Übersicht

| Datei | Art | Zweck |
|---|---|---|
| `src/Entity/FresApiToken.php` | NEU | Doctrine-Entity für die Tabelle |
| `src/Repository/FresApiTokenRepository.php` | NEU | Custom Repository für Token-Lookups |
| `src/Security/BearerTokenAuthenticator.php` | NEU | Authenticator |
| `src/Controller/Api/TokenController.php` | NEU | POST/DELETE/GET-Endpunkte |
| `sql/2026-06-26-api-tokens.sql` | NEU | Schema-Snippet zum manuellen Deploy |
| `config/packages/security.yaml` | UPDATE | Authenticator + access_control registrieren |
| `config/packages/rate_limiter.yaml` | NEU | Rate-Limiter-Definition |

## Tests

- Unit: `BearerTokenAuthenticator::supports()` — true nur mit Bearer-Header
- Unit: Token-Generierung — Prefix korrekt, Länge korrekt, Hash deterministisch
- Integration: `POST /api/tokens` mit gültigen/ungültigen Credentials, mit Rate-Limit-Überschreitung
- Integration: `GET /api/bookings` mit gültigem Bearer → 200; mit ungültigem → 401; ohne Header (PWA-Cookie-Path) → weiterhin 200/401 je nach Cookie
- Integration: `DELETE /api/tokens/current` invalidiert sofort

## Offene Punkte (für Implementierungsplan)

- Wie genau wird `last_used_at`/`last_ip` aktualisiert? Synchron im Authenticator (1 zusätzlicher Write pro Request) oder über Symfony-Event-Listener? **Default: synchron im Authenticator** — bei der erwarteten Last (Vereins-Flugschule) irrelevant
- Soll `apiLogin()` (Cookie-Variante) zusätzlich auch einen Bearer-Token zurückgeben können (für eine künftige Hybrid-Variante)? **Nein in v1** — separat halten
- Welcher Test-Framework wird im Projekt verwendet? Muss in der Plan-Phase aus `composer.json` / vorhandenen Tests verifiziert werden
