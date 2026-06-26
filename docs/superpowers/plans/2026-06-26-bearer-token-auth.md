# Bearer-Token-Authentifizierung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parallele Bearer-Token-Authentifizierung neben Cookie-Auth, damit eine native FlutterFlow-App `/api/*` zuverlässig nutzen kann.

**Architecture:** Neuer `BearerTokenAuthenticator` in derselben Symfony-`main`-Firewall, neue Tabelle `FRes_api_tokens` mit SHA-256-Hashes, neuer `TokenController` mit 4 Endpunkten, CSRF-Helper im `ApiController` erkennt Bearer und überspringt Origin-Check.

**Tech Stack:** Symfony 6.4, Doctrine ORM 2.16 (Annotations-Style), PHP 8.1+, PHPUnit 10.5, MySQL.

**Spec:** [`docs/superpowers/specs/2026-06-26-bearer-token-auth-design.md`](../specs/2026-06-26-bearer-token-auth-design.md)

---

## Datei-Übersicht

**Neu:**
- `sql/2026-06-26-api-tokens.sql` — Schema-Snippet
- `src/Entity/FresApiToken.php` — Doctrine-Entity
- `src/Repository/FresApiTokenRepository.php` — Repository
- `src/Service/ApiTokenService.php` — Token-Generierung & Hashing (isoliert testbar)
- `src/Security/BearerTokenAuthenticator.php` — Authenticator
- `src/Controller/Api/TokenController.php` — 4 Endpunkte
- `config/packages/rate_limiter.yaml` — Rate-Limit-Config
- `tests/Service/ApiTokenServiceTest.php` — Unit-Tests
- `tests/Security/BearerTokenAuthenticatorTest.php` — Unit-Tests
- `docs/bearer-token-smoke-test.md` — manuelle curl-Tests

**Geändert:**
- `config/packages/security.yaml` — Authenticator + access_control registrieren
- `config/routes/api.yaml` — 4 neue Routen
- `src/Controller/Api/ApiController.php` — `denyCrossOrigin()` überspringt bei Bearer

---

## Task 1: SQL-Schema

**Files:**
- Create: `sql/2026-06-26-api-tokens.sql`

- [ ] **Step 1: Schema-Datei anlegen**

```sql
-- FRes_api_tokens: Bearer-Tokens fuer die FlutterFlow-App (parallel zu Cookie-Auth)
-- Manuell ausfuehren: mysql -u <user> -p <database> < sql/2026-06-26-api-tokens.sql

CREATE TABLE FRes_api_tokens (
    id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id       INT               NOT NULL,
    token_hash    CHAR(64)          NOT NULL,
    device_name   VARCHAR(100)      DEFAULT NULL,
    user_agent    VARCHAR(255)      DEFAULT NULL,
    last_ip       VARCHAR(45)       DEFAULT NULL,
    created_at    DATETIME          NOT NULL,
    last_used_at  DATETIME          DEFAULT NULL,
    expires_at    DATETIME          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_token_hash (token_hash),
    KEY idx_user_last_used (user_id, last_used_at),
    CONSTRAINT fk_api_tokens_user
        FOREIGN KEY (user_id) REFERENCES FRes_accounts (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Datei stagen + committen**

```bash
git add sql/2026-06-26-api-tokens.sql
git commit -m "db: Schema fuer FRes_api_tokens (Bearer-Auth)"
```

> ℹ️ Das Schema wird **nicht automatisch** ausgeführt. Vor dem Manuelltest in Task 14 muss der Operator die SQL-Datei einmal manuell anwenden.

---

## Task 2: ApiTokenService (TDD)

Reine Logik — Token-Generierung & Hashing. Isoliert ohne DB testbar.

**Files:**
- Create: `src/Service/ApiTokenService.php`
- Create: `tests/Service/ApiTokenServiceTest.php`

- [ ] **Step 1: Failing-Test schreiben**

```php
<?php
// tests/Service/ApiTokenServiceTest.php
namespace App\Tests\Service;

use App\Service\ApiTokenService;
use PHPUnit\Framework\TestCase;

class ApiTokenServiceTest extends TestCase
{
    public function testGenerateReturnsTokenWithPrefixAndCorrectLength(): void
    {
        $svc = new ApiTokenService();
        $token = $svc->generate();

        $this->assertStringStartsWith('flyres_', $token);
        // 7 (Prefix) + 43 (base64url von 32 Bytes ohne Padding) = 50
        $this->assertSame(50, strlen($token));
        $this->assertMatchesRegularExpression('/^flyres_[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testGenerateProducesUniqueTokens(): void
    {
        $svc = new ApiTokenService();
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = $svc->generate();
        }
        $this->assertCount(100, array_unique($tokens));
    }

    public function testHashReturnsSha256HexOfFullToken(): void
    {
        $svc = new ApiTokenService();
        $token = 'flyres_test123';
        $expected = hash('sha256', $token);

        $this->assertSame($expected, $svc->hash($token));
        $this->assertSame(64, strlen($svc->hash($token)));
    }

    public function testHashIsDeterministic(): void
    {
        $svc = new ApiTokenService();
        $token = $svc->generate();
        $this->assertSame($svc->hash($token), $svc->hash($token));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
vendor/bin/phpunit tests/Service/ApiTokenServiceTest.php
```
Erwartet: FAIL — "Class App\\Service\\ApiTokenService not found".

- [ ] **Step 3: Service implementieren**

```php
<?php
// src/Service/ApiTokenService.php
namespace App\Service;

/**
 * Token-Generierung und -Hashing fuer die API-Bearer-Authentifizierung.
 *
 * Klartext-Token-Format: "flyres_" + base64url(32 random bytes), 50 Zeichen.
 * In der DB wird ausschliesslich der SHA-256-Hex-Hash gespeichert.
 */
class ApiTokenService
{
    private const PREFIX = 'flyres_';
    private const RANDOM_BYTES = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::RANDOM_BYTES);
        // base64url ohne Padding: +/= durch -_ ersetzen, = strippen
        $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        return self::PREFIX . $b64;
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

```bash
vendor/bin/phpunit tests/Service/ApiTokenServiceTest.php
```
Erwartet: PASS (4 Tests, 4 Assertions+).

- [ ] **Step 5: Commit**

```bash
git add src/Service/ApiTokenService.php tests/Service/ApiTokenServiceTest.php
git commit -m "feat: ApiTokenService fuer Generierung & Hashing"
```

---

## Task 3: FresApiToken Entity

**Files:**
- Create: `src/Entity/FresApiToken.php`

- [ ] **Step 1: Entity anlegen** (Doctrine-Annotations-Style, passend zu `FresAccounts`)

```php
<?php
// src/Entity/FresApiToken.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\FresApiTokenRepository")
 * @ORM\Table(
 *     name="FRes_api_tokens",
 *     indexes={
 *         @ORM\Index(name="idx_user_last_used", columns={"user_id", "last_used_at"})
 *     },
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uk_token_hash", columns={"token_hash"})
 *     }
 * )
 */
class FresApiToken
{
    /**
     * @ORM\Column(type="bigint", options={"unsigned": true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private ?string $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\FresAccounts")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private FresAccounts $user;

    /**
     * @ORM\Column(name="token_hash", type="string", length=64)
     */
    private string $tokenHash;

    /**
     * @ORM\Column(name="device_name", type="string", length=100, nullable=true)
     */
    private ?string $deviceName = null;

    /**
     * @ORM\Column(name="user_agent", type="string", length=255, nullable=true)
     */
    private ?string $userAgent = null;

    /**
     * @ORM\Column(name="last_ip", type="string", length=45, nullable=true)
     */
    private ?string $lastIp = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(name="last_used_at", type="datetime", nullable=true)
     */
    private ?\DateTime $lastUsedAt = null;

    /**
     * @ORM\Column(name="expires_at", type="datetime", nullable=true)
     */
    private ?\DateTime $expiresAt = null;

    public function __construct(FresAccounts $user, string $tokenHash)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id === null ? null : (int) $this->id; }
    public function getUser(): FresAccounts { return $this->user; }
    public function getTokenHash(): string { return $this->tokenHash; }

    public function getDeviceName(): ?string { return $this->deviceName; }
    public function setDeviceName(?string $v): self { $this->deviceName = $v; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): self { $this->userAgent = $v; return $this; }

    public function getLastIp(): ?string { return $this->lastIp; }
    public function setLastIp(?string $v): self { $this->lastIp = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLastUsedAt(): ?\DateTime { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTime $v): self { $this->lastUsedAt = $v; return $this; }

    public function getExpiresAt(): ?\DateTime { return $this->expiresAt; }
    public function setExpiresAt(?\DateTime $v): self { $this->expiresAt = $v; return $this; }
}
```

- [ ] **Step 2: Doctrine-Validation prüfen**

```bash
php bin/console doctrine:schema:validate
```
Erwartet: „[Mapping] OK" für die Mappings (die ggf. existierende Sync-Warnung darunter ist erwartet — wir nutzen kein `doctrine:schema:update`).

- [ ] **Step 3: Commit**

```bash
git add src/Entity/FresApiToken.php
git commit -m "feat: FresApiToken Entity"
```

---

## Task 4: FresApiTokenRepository

**Files:**
- Create: `src/Repository/FresApiTokenRepository.php`

- [ ] **Step 1: Repository implementieren**

```php
<?php
// src/Repository/FresApiTokenRepository.php
namespace App\Repository;

use App\Entity\FresApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FresApiToken>
 */
class FresApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FresApiToken::class);
    }

    public function findByHash(string $tokenHash): ?FresApiToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function save(FresApiToken $token, bool $flush = true): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function delete(FresApiToken $token, bool $flush = true): void
    {
        $this->getEntityManager()->remove($token);
        if ($flush) $this->getEntityManager()->flush();
    }
}
```

- [ ] **Step 2: Container-Lint**

```bash
php bin/console lint:container
```
Erwartet: keine Fehler zum neuen Repository.

- [ ] **Step 3: Commit**

```bash
git add src/Repository/FresApiTokenRepository.php
git commit -m "feat: FresApiTokenRepository"
```

---

## Task 5: BearerTokenAuthenticator (TDD)

**Files:**
- Create: `src/Security/BearerTokenAuthenticator.php`
- Create: `tests/Security/BearerTokenAuthenticatorTest.php`

- [ ] **Step 1: Failing-Test schreiben** (nur die `supports()`-Logik isoliert — die DB-Lookup-Logik wird in Task 14 manuell verifiziert)

```php
<?php
// tests/Security/BearerTokenAuthenticatorTest.php
namespace App\Tests\Security;

use App\Repository\FresApiTokenRepository;
use App\Security\BearerTokenAuthenticator;
use App\Service\ApiTokenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class BearerTokenAuthenticatorTest extends TestCase
{
    private function makeAuthenticator(): BearerTokenAuthenticator
    {
        return new BearerTokenAuthenticator(
            $this->createMock(FresApiTokenRepository::class),
            new ApiTokenService(),
        );
    }

    public function testSupportsReturnsTrueOnBearerHeader(): void
    {
        $auth = $this->makeAuthenticator();
        $req = new Request();
        $req->headers->set('Authorization', 'Bearer flyres_xyz');

        $this->assertTrue($auth->supports($req));
    }

    public function testSupportsReturnsFalseWithoutHeader(): void
    {
        $this->assertFalse($this->makeAuthenticator()->supports(new Request()));
    }

    public function testSupportsReturnsFalseForBasicAuth(): void
    {
        $auth = $this->makeAuthenticator();
        $req = new Request();
        $req->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $this->assertFalse($auth->supports($req));
    }

    public function testSupportsReturnsFalseForEmptyBearer(): void
    {
        $auth = $this->makeAuthenticator();
        $req = new Request();
        $req->headers->set('Authorization', 'Bearer ');

        $this->assertFalse($auth->supports($req));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
vendor/bin/phpunit tests/Security/BearerTokenAuthenticatorTest.php
```
Erwartet: FAIL — Klasse fehlt.

- [ ] **Step 3: Authenticator implementieren**

```php
<?php
// src/Security/BearerTokenAuthenticator.php
namespace App\Security;

use App\Repository\FresApiTokenRepository;
use App\Service\ApiTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Bearer-Token-Authenticator fuer die FlutterFlow-App.
 *
 * Triggert ausschliesslich, wenn ein "Authorization: Bearer <token>"-Header
 * vorhanden ist. Andernfalls faellt die Anfrage durch zum naechsten
 * Authenticator (Cookie-basierter LoginFormAuthenticator).
 *
 * Bei gueltigem Token werden last_used_at, last_ip und user_agent aktualisiert.
 */
class BearerTokenAuthenticator extends AbstractAuthenticator
{
    public const REQUEST_ATTR = '_auth_bearer';

    public function __construct(
        private readonly FresApiTokenRepository $tokens,
        private readonly ApiTokenService $tokenService,
    ) {}

    public function supports(Request $request): ?bool
    {
        $h = $request->headers->get('Authorization');
        return $h !== null && str_starts_with($h, 'Bearer ') && trim(substr($h, 7)) !== '';
    }

    public function authenticate(Request $request): Passport
    {
        $raw = substr($request->headers->get('Authorization'), 7);
        $hash = $this->tokenService->hash($raw);

        $apiToken = $this->tokens->findByHash($hash);
        if ($apiToken === null) {
            throw new CustomUserMessageAuthenticationException('invalid_token');
        }
        $expires = $apiToken->getExpiresAt();
        if ($expires !== null && $expires < new \DateTime()) {
            throw new CustomUserMessageAuthenticationException('token_expired');
        }

        // Last-used + Audit-Update (synchron, 1 Write pro Request)
        $apiToken->setLastUsedAt(new \DateTime());
        $apiToken->setLastIp($request->getClientIp());
        $apiToken->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));
        $this->tokens->save($apiToken);

        // Markierung fuer ApiController::denyCrossOrigin() — Bearer-Requests
        // brauchen keinen Origin-Check (mobile App sendet keinen Origin-Header).
        $request->attributes->set(self::REQUEST_ATTR, $apiToken->getId());

        $username = $apiToken->getUser()->getUsername();
        return new SelfValidatingPassport(new UserBadge($username));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // Request weiterlaufen lassen
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessageKey() ?: 'invalid_token'], Response::HTTP_UNAUTHORIZED);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

```bash
vendor/bin/phpunit tests/Security/BearerTokenAuthenticatorTest.php
```
Erwartet: PASS (4 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Security/BearerTokenAuthenticator.php tests/Security/BearerTokenAuthenticatorTest.php
git commit -m "feat: BearerTokenAuthenticator + Unit-Tests"
```

---

## Task 6: ApiController::denyCrossOrigin() überspringt bei Bearer

Mobile Apps senden weder `Origin` noch `Referer`. Aktuelle `denyCrossOrigin()` würde Bearer-authentifizierte POST/PATCH/DELETE-Requests blockieren. Lösung: wenn der Authenticator vorher ausgeführt wurde (Request-Attribut gesetzt), Origin-Check überspringen.

**Files:**
- Modify: `src/Controller/Api/ApiController.php`

- [ ] **Step 1: Methode am Anfang um Bearer-Check ergänzen**

Aktuelle Methode (Zeile 51 in `src/Controller/Api/ApiController.php`):

```php
protected function denyCrossOrigin(Request $request): ?JsonResponse
{
    $expectedHost = $request->getHost();
    // …
}
```

Wird zu:

```php
protected function denyCrossOrigin(Request $request): ?JsonResponse
{
    // Bearer-authentifizierte Requests sind nicht browser-basiert und
    // brauchen keinen Origin-/Referer-Check (mobile Apps senden keinen Origin).
    if ($request->attributes->has(\App\Security\BearerTokenAuthenticator::REQUEST_ATTR)) {
        return null;
    }

    $expectedHost = $request->getHost();
    // … (Rest unveraendert)
}
```

- [ ] **Step 2: Vollständigen Methodenrumpf prüfen**

Mit `git diff` checken: nur 4 Zeilen oben in `denyCrossOrigin()` neu, der Rest unverändert.

- [ ] **Step 3: Container-Lint**

```bash
php bin/console lint:container
```
Erwartet: keine Fehler.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Api/ApiController.php
git commit -m "fix: denyCrossOrigin ueberspringt Bearer-Requests"
```

---

## Task 7: Rate Limiter Config

**Files:**
- Create: `config/packages/rate_limiter.yaml`

- [ ] **Step 1: Config-Datei anlegen**

```yaml
# config/packages/rate_limiter.yaml
# Brute-Force-Schutz fuer POST /api/tokens (App-Login).
framework:
    rate_limiter:
        api_token_login:
            policy: 'token_bucket'
            limit: 5
            rate: { interval: '1 minute', amount: 5 }
```

- [ ] **Step 2: Cache löschen + Container-Lint**

```bash
php bin/console cache:clear
php bin/console lint:container
```
Erwartet: keine Fehler. Service `limiter.api_token_login` ist im Container registriert (siehe `php bin/console debug:container limiter` — sollte den Service listen).

- [ ] **Step 3: Commit**

```bash
git add config/packages/rate_limiter.yaml
git commit -m "feat: Rate-Limiter fuer api_token_login (5/min)"
```

---

## Task 8: TokenController — POST /api/tokens

**Files:**
- Create: `src/Controller/Api/TokenController.php`
- Modify: `config/routes/api.yaml`

- [ ] **Step 1: Controller-Grundgerüst + POST-Endpunkt**

```php
<?php
// src/Controller/Api/TokenController.php
namespace App\Controller\Api;

use App\Entities\Clients;
use App\Entities\Users;
use App\Entity\FresApiToken;
use App\Repository\FresApiTokenRepository;
use App\Security\BearerTokenAuthenticator;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

/**
 * Endpunkte fuer Bearer-Token (App-Login, Logout, Verwaltung).
 *
 * - POST   /api/tokens         → Login, gibt Klartext-Token + User zurueck
 * - DELETE /api/tokens/current → aktuelles Token widerrufen (App-Logout)
 * - GET    /api/tokens         → eigene Tokens auflisten
 * - DELETE /api/tokens/{id}    → einzelnes anderes Token widerrufen
 */
class TokenController extends ApiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FresApiTokenRepository $tokens,
        private readonly ApiTokenService $tokenService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserCheckerInterface $userChecker,
        private readonly RateLimiterFactory $apiTokenLoginLimiter,
    ) {}

    public function create(Request $request): JsonResponse
    {
        // Rate-Limit pro IP (Brute-Force-Schutz, nicht pro Username, sonst kann
        // ein Angreifer den Account des Opfers gezielt lockout-en)
        $limiter = $this->apiTokenLoginLimiter->create($request->getClientIp() ?? 'unknown');
        if (false === $limiter->consume(1)->isAccepted()) {
            return $this->json(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $username   = $data['username']    ?? null;
        $password   = $data['password']    ?? null;
        $clientName = $data['client']      ?? null;
        $deviceName = $data['device_name'] ?? null;

        if (!$username || !$password) {
            return $this->json(['error' => 'missing_credentials'], Response::HTTP_BAD_REQUEST);
        }

        $clientid = $clientName ? Clients::GetClientIdByName($this->em, $clientName) : 1;
        $user = Users::GetUserObjectByName($this->em, $username, $clientid);
        if (!$user || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'invalid_credentials'], Response::HTTP_UNAUTHORIZED);
        }
        if (Users::isDeleted($user) || Users::isLocked($user)) {
            return $this->json(['error' => 'account_locked'], Response::HTTP_FORBIDDEN);
        }
        try {
            $this->userChecker->checkPreAuth($user);
        } catch (\Throwable) {
            return $this->json(['error' => 'account_not_allowed'], Response::HTTP_FORBIDDEN);
        }

        $plain = $this->tokenService->generate();
        $token = new FresApiToken($user, $this->tokenService->hash($plain));
        if ($deviceName) {
            $token->setDeviceName(mb_substr($deviceName, 0, 100));
        }
        $token->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));
        $token->setLastIp($request->getClientIp());
        $this->tokens->save($token);

        $roles = $user->getRoles();
        return $this->json([
            'token' => $plain, // **einmaliger** Klartext-Wert
            'user'  => [
                'id'           => $user->getId(),
                'username'     => $user->getUsername(),
                'firstname'    => $user->getFirstname(),
                'lastname'     => $user->getLastname(),
                'email'        => $user->getEmail(),
                'clientid'     => $user->getClientid(),
                'roles'        => $roles,
                'isInstructor' => in_array('ROLE_FI', $roles, true),
                'isAdmin'      => in_array('ROLE_ADMIN', $roles, true),
            ],
        ]);
    }
}
```

- [ ] **Step 2: Route registrieren** (in `config/routes/api.yaml` ans Ende anhängen)

```yaml
# --- Bearer-Token fuer App-Login (NEU) ---
api_tokens_create:
    path: /api/tokens
    controller: App\Controller\Api\TokenController::create
    methods: POST
```

- [ ] **Step 3: `access_control` ergänzen** (`config/packages/security.yaml`)

Bestehende Liste nach `^/api/verify` ergänzen:

```yaml
        - { path: ^/api/tokens$, methods: [POST], roles: PUBLIC_ACCESS }
```

> Die anderen `/api/tokens*`-Routen brauchen Auth → keine `PUBLIC_ACCESS`-Regel (Default = nicht öffentlich, aber `/api/*` ist in der bestehenden Liste schon als `PUBLIC_ACCESS` markiert → siehe Task 11 für die Anpassung).

- [ ] **Step 4: Cache löschen**

```bash
php bin/console cache:clear
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Api/TokenController.php config/routes/api.yaml config/packages/security.yaml
git commit -m "feat: POST /api/tokens (App-Login)"
```

---

## Task 9: Authenticator in security.yaml registrieren

**Files:**
- Modify: `config/packages/security.yaml`

- [ ] **Step 1: `custom_authenticators` erweitern**

Im `main`-Firewall (Zeile ~20):

```yaml
        main:
            custom_authenticators:
              - App\Security\BearerTokenAuthenticator   # <- NEU, MUSS vor LoginFormAuthenticator stehen
              - App\Security\LoginFormAuthenticator
            entry_point: App\Security\LoginFormAuthenticator
            # … Rest unveraendert
```

- [ ] **Step 2: Cache löschen + Container-Lint**

```bash
php bin/console cache:clear
php bin/console lint:container
```
Erwartet: keine Fehler.

- [ ] **Step 3: Commit**

```bash
git add config/packages/security.yaml
git commit -m "feat: BearerTokenAuthenticator in main-Firewall registriert"
```

---

## Task 10: TokenController — DELETE /api/tokens/current

**Files:**
- Modify: `src/Controller/Api/TokenController.php`
- Modify: `config/routes/api.yaml`

- [ ] **Step 1: Methode hinzufügen**

```php
public function revokeCurrent(Request $request): JsonResponse
{
    $user = $this->requirePilot();
    if ($user instanceof JsonResponse) return $user;

    $currentId = $request->attributes->get(BearerTokenAuthenticator::REQUEST_ATTR);
    if ($currentId === null) {
        return $this->json(['error' => 'no_token_in_request'], Response::HTTP_BAD_REQUEST);
    }

    $token = $this->tokens->find($currentId);
    if ($token === null || $token->getUser()->getId() !== $user->getId()) {
        return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }
    $this->tokens->delete($token);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

- [ ] **Step 2: Route registrieren**

```yaml
api_tokens_revoke_current:
    path: /api/tokens/current
    controller: App\Controller\Api\TokenController::revokeCurrent
    methods: DELETE
```

- [ ] **Step 3: Cache löschen**

```bash
php bin/console cache:clear
```

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Api/TokenController.php config/routes/api.yaml
git commit -m "feat: DELETE /api/tokens/current (App-Logout)"
```

---

## Task 11: TokenController — GET /api/tokens + DELETE /api/tokens/{id}

**Files:**
- Modify: `src/Controller/Api/TokenController.php`
- Modify: `config/routes/api.yaml`
- Modify: `config/packages/security.yaml`

- [ ] **Step 1: Beide Methoden ergänzen**

```php
public function list(Request $request): JsonResponse
{
    $user = $this->requirePilot();
    if ($user instanceof JsonResponse) return $user;

    $currentId = $request->attributes->get(BearerTokenAuthenticator::REQUEST_ATTR);

    $items = $this->tokens->findBy(['user' => $user], ['lastUsedAt' => 'DESC', 'createdAt' => 'DESC']);
    $out = [];
    foreach ($items as $t) {
        $out[] = [
            'id'           => $t->getId(),
            'device_name'  => $t->getDeviceName(),
            'created_at'   => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'last_used_at' => $t->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            'last_ip'      => $t->getLastIp(),
            'is_current'   => $t->getId() === $currentId,
        ];
    }
    return $this->json($out);
}

public function revoke(Request $request, int $id): JsonResponse
{
    $user = $this->requirePilot();
    if ($user instanceof JsonResponse) return $user;

    $token = $this->tokens->find($id);
    if ($token === null || $token->getUser()->getId() !== $user->getId()) {
        return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }
    $this->tokens->delete($token);

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

- [ ] **Step 2: Routen registrieren** (in `config/routes/api.yaml`)

```yaml
api_tokens_list:
    path: /api/tokens
    controller: App\Controller\Api\TokenController::list
    methods: GET

api_tokens_revoke:
    path: /api/tokens/{id}
    controller: App\Controller\Api\TokenController::revoke
    methods: DELETE
    requirements:
        id: '\d+'
```

- [ ] **Step 3: `access_control` für `/api/tokens` (GET/DELETE) ergänzen** (`security.yaml`)

Die bestehende Sammelregel deckt `/api/tokens` nicht ab (nicht in der Liste der erlaubten Pfade). Die bestehende Regel ergänzen:

Aktuell:
```yaml
- { path: ^/api/(me|aircraft|instructors|pilots|flightpurposes|airfields|bookings|availability)(/|$), roles: PUBLIC_ACCESS }
```

Wird zu (`tokens` hinzufügen):
```yaml
- { path: ^/api/(me|aircraft|instructors|pilots|flightpurposes|airfields|bookings|availability|tokens)(/|$), roles: PUBLIC_ACCESS }
```

Der `requirePilot()`-Check im Controller blockiert dann unauthentifizierte GET/DELETE-Zugriffe mit `401`.

- [ ] **Step 4: Cache löschen**

```bash
php bin/console cache:clear
```

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Api/TokenController.php config/routes/api.yaml config/packages/security.yaml
git commit -m "feat: GET /api/tokens + DELETE /api/tokens/{id}"
```

---

## Task 12: Smoke-Test-Dokumentation

End-to-End-Tests gegen die laufende API mit `curl`. Da keine Test-DB-Infrastruktur existiert, dokumentieren wir manuelle Tests.

**Files:**
- Create: `docs/bearer-token-smoke-test.md`

- [ ] **Step 1: Test-Dokumentation anlegen**

```markdown
# Bearer-Token: Manueller Smoke-Test

Voraussetzungen:
- `sql/2026-06-26-api-tokens.sql` wurde gegen die Ziel-DB ausgeführt
- FlyRes läuft lokal (z. B. `symfony serve` oder Apache)
- Gültige Zugangsdaten (Username + Passwort + Client-Name) liegen vor

## 1. Token anfordern (Login)

```bash
curl -i -X POST http://localhost:8000/api/tokens \
  -H 'Content-Type: application/json' \
  -d '{"username":"<USER>","password":"<PASS>","client":"ASW","device_name":"Test-Curl"}'
```

Erwartet: `200 OK`, JSON mit `token` (Format `flyres_…`) und `user`-Objekt.
Token kopieren — er ist nur **einmal** sichtbar.

## 2. Authentifizierter Request (Daten lesen)

```bash
TOKEN='flyres_xxx…'
curl -i http://localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
```

Erwartet: `200 OK`, User-JSON (selber Inhalt wie aus Schritt 1).

## 3. Falsches Token

```bash
curl -i http://localhost:8000/api/me -H 'Authorization: Bearer flyres_invalid'
```

Erwartet: `401 Unauthorized`, `{"error":"invalid_token"}`.

## 4. Eigene Tokens auflisten

```bash
curl -i http://localhost:8000/api/tokens -H "Authorization: Bearer $TOKEN"
```

Erwartet: `200`, Array mit mindestens einem Eintrag, `is_current: true`.

## 5. Aktuelles Token widerrufen (Logout)

```bash
curl -i -X DELETE http://localhost:8000/api/tokens/current -H "Authorization: Bearer $TOKEN"
```

Erwartet: `204 No Content`.

## 6. Nach Widerruf nicht mehr nutzbar

```bash
curl -i http://localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
```

Erwartet: `401`.

## 7. Rate-Limit

```bash
for i in 1 2 3 4 5 6; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/api/tokens \
    -H 'Content-Type: application/json' \
    -d '{"username":"falsch","password":"falsch","client":"ASW"}'
done
```

Erwartet: 5x `401` (invalid_credentials), dann `429` (rate_limited).

## 8. PWA bleibt funktional (Regressionstest)

```bash
# Cookie-Login wie bisher
curl -i -c cookies.txt -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"<USER>","password":"<PASS>","client":"ASW"}'

# Authentifizierter Cookie-Request
curl -i -b cookies.txt http://localhost:8000/api/me
```

Erwartet: beide `200`. Cookie-Auth wurde durch Bearer-Authenticator **nicht** gebrochen.

## 9. Cross-Origin-Check übersprungen bei Bearer

POST mit Bearer, **ohne** Origin-Header:

```bash
curl -i -X POST http://localhost:8000/api/bookings \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{...gueltiges-booking...}'
```

Erwartet: kein `origin_required`-Fehler (Bearer-Marker setzt denyCrossOrigin außer Kraft). Antwort hängt vom Booking-Payload ab, aber **nicht** `403 origin_required`.
```

- [ ] **Step 2: Commit**

```bash
git add docs/bearer-token-smoke-test.md
git commit -m "docs: Smoke-Test-Dokumentation fuer Bearer-Auth"
```

---

## Task 13: Smoke-Test ausführen + Fixes

- [ ] **Step 1: SQL-Schema in lokale DB einspielen**

```bash
mysql -u <user> -p <database> < sql/2026-06-26-api-tokens.sql
```

- [ ] **Step 2: Symfony-Cache löschen + Server starten**

```bash
php bin/console cache:clear
symfony serve   # oder: php -S 127.0.0.1:8000 -t public
```

- [ ] **Step 3: Alle 9 Schritte aus `docs/bearer-token-smoke-test.md` durchgehen**

Bei jedem Schritt das tatsächliche Ergebnis notieren. Bei Abweichung vom erwarteten Output → Bug-Fix-Commit, dann Schritt wiederholen.

- [ ] **Step 4: Final-Check — Unit-Tests laufen lassen**

```bash
vendor/bin/phpunit
```
Erwartet: alle Tests grün (Service + Authenticator).

- [ ] **Step 5: Erfolgs-Commit (falls Fixes nötig waren)**

```bash
git status   # muss clean sein, sonst die Fixes committen
```

---

## Self-Review

**Spec-Abdeckung:**
- Datenmodell `FRes_api_tokens` (alle 9 Spalten) → Task 1 (SQL), Task 3 (Entity) ✓
- Token-Format `flyres_` + base64url(32) → Task 2 ✓
- Endpunkt `POST /api/tokens` → Task 8 ✓
- Endpunkt `DELETE /api/tokens/current` → Task 10 ✓
- Endpunkt `GET /api/tokens` → Task 11 ✓
- Endpunkt `DELETE /api/tokens/{id}` → Task 11 ✓
- BearerTokenAuthenticator → Task 5 ✓
- security.yaml-Änderungen → Task 8 (POST PUBLIC_ACCESS), Task 9 (Authenticator), Task 11 (GET/DELETE Auth) ✓
- Rate-Limiter `5/min` → Task 7 + Task 8 (Aufruf) ✓
- SHA-256-Hash → Task 2 ✓
- Audit (last_used_at, last_ip, user_agent) → Task 5 (Authenticator-Update) + Task 8 (Init beim Create) ✓
- Tests → Task 2, 5 (Unit), Task 12+13 (Smoke) ✓
- CSRF-Workaround für Bearer → Task 6 ✓

**Placeholder-Scan:** keine TBDs, kein „handle errors", kein „similar to" ✓

**Type-Konsistenz:**
- `FresApiToken::getId()` → `?int` (Task 3) — verwendet in Task 10, 11 als int-Vergleich ✓
- `ApiTokenService::generate()` → `string`, `hash()` → `string` — passt zur Verwendung in Task 5, 8 ✓
- `BearerTokenAuthenticator::REQUEST_ATTR` als Const definiert (Task 5), referenziert in Task 6, 10, 11 ✓

Plan fertig.
