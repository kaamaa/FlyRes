# API-Token FK-Entfernung, Casing-Fix + aktive Token-Bereinigung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das `fres_api_tokens`-Schema FK-frei und kleingeschrieben machen und die durch den Wegfall von `ON DELETE CASCADE` verlorene Aufräumung durch aktive Token-Bereinigung beim Sperren/Löschen eines Nutzers ersetzen.

**Architecture:** Reines SQL-/ORM-Mapping-Update plus eine neue Repository-Bulk-Delete-Methode, die aus den bestehenden zentralen Sperr-/Lösch-Pfaden (`Users::DeleteUser`, `EditUserController::SaveAction`) aufgerufen wird. Die Entscheidung „nur beim Übergang entsperrt→gesperrt aufräumen" wird in eine reine, unit-testbare Hilfsfunktion ausgelagert.

**Tech Stack:** PHP 8 / Symfony 6.4, Doctrine ORM (Annotations), MySQL (gemischt MyISAM/InnoDB), PHPUnit 9.5.

## Global Constraints

- Tabellen-Namen kleingeschrieben: `fres_api_tokens`, FK-Ziel `fres_accounts` (verbatim aus Maintainer-Feedback).
- **Keine** Foreign Keys (Ziel `fres_accounts` ist MyISAM; InnoDB→MyISAM-FK ist unmöglich).
- Engine der neuen Tabelle bleibt `InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- Indizes `uk_token_hash` und `idx_user_last_used (user_id, last_used_at)` bleiben erhalten.
- `fres_accounts` wird **nicht** verändert (keine Engine-Umstellung).
- Test-Konvention dieses Projekts: reine PHPUnit-`TestCase`-Unit-Tests mit Mocks, **kein** Kernel/DB-Harness. Repositories werden gemockt, nicht instanziiert. DB-/ORM-/Controller-Wiring wird per Konsolen-Kommando (Syntax + Container-Compile) bzw. manuell verifiziert, nicht über DB-Integrationstests.
- PHP-Tests laufen via `php vendor/bin/phpunit` (Windows-Shell: PowerShell).

---

### Task 1: SQL — FK entfernen, Tabelle kleinschreiben

**Files:**
- Modify: `sql/2026-06-26-api-tokens.sql`

**Interfaces:**
- Consumes: nichts.
- Produces: Tabelle `fres_api_tokens` (FK-frei) — Referenzname für Task 2 (`@ORM\Table(name="fres_api_tokens")`).

- [ ] **Step 1: Datei ersetzen**

Vollständiger neuer Inhalt von `sql/2026-06-26-api-tokens.sql`:

```sql
-- fres_api_tokens: Bearer-Tokens fuer die FlutterFlow-App (parallel zu Cookie-Auth)
-- Manuell ausfuehren: mysql -u <user> -p <database> < sql/2026-06-26-api-tokens.sql
-- Hinweis: Kein Foreign Key auf fres_accounts, da fres_accounts eine MyISAM-Tabelle
-- ist (InnoDB kann keinen FK auf MyISAM anlegen). Aufraeumen verwaister Tokens
-- erfolgt aktiv im Code (Users::DeleteUser, EditUserController::SaveAction).

CREATE TABLE fres_api_tokens (
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
    KEY idx_user_last_used (user_id, last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Verifizieren — kein FK, kleingeschrieben**

Run: `grep -niE "foreign key|constraint|FRes_" sql/2026-06-26-api-tokens.sql`
Expected: **keine Ausgabe** (kein FK/CONSTRAINT, kein `FRes_`).

Run: `grep -ni "fres_api_tokens\|fres_accounts\|idx_user_last_used\|uk_token_hash" sql/2026-06-26-api-tokens.sql`
Expected: Tabellenname `fres_api_tokens` und beide Index-Namen vorhanden.

- [ ] **Step 3: Commit**

```bash
git add sql/2026-06-26-api-tokens.sql
git commit -m "fix(sql): fres_api_tokens kleingeschrieben + Foreign Key entfernt (MyISAM-Ziel)"
```

---

### Task 2: Entity — Tabelle kleinschreiben, onDelete-Hint entfernen

**Files:**
- Modify: `src/Entity/FresApiToken.php:9` (Tabellenname) und `:29` (JoinColumn)

**Interfaces:**
- Consumes: Tabellenname `fres_api_tokens` aus Task 1.
- Produces: unverändertes öffentliches Entity-API (Konstruktor + Getter/Setter bleiben gleich).

- [ ] **Step 1: Tabellennamen kleinschreiben**

In `src/Entity/FresApiToken.php` die Zeile

```php
 *     name="FRes_api_tokens",
```

ersetzen durch

```php
 *     name="fres_api_tokens",
```

- [ ] **Step 2: `onDelete="CASCADE"` aus der JoinColumn entfernen**

Die Zeile

```php
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
```

ersetzen durch

```php
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
```

(Die `@ORM\ManyToOne`-Zeile darüber bleibt unverändert — die Assoziation funktioniert auch ohne DB-FK.)

- [ ] **Step 3: Syntax prüfen**

Run: `php -l src/Entity/FresApiToken.php`
Expected: `No syntax errors detected in src/Entity/FresApiToken.php`

- [ ] **Step 4: Mapping/Container compilieren (fängt kaputte Annotations ab)**

Run: `php bin/console cache:clear --env=dev`
Expected: `[OK] Cache for the "dev" environment ... was successfully cleared.` (keine Annotation-/Mapping-Fehler)

- [ ] **Step 5: Commit**

```bash
git add src/Entity/FresApiToken.php
git commit -m "fix(entity): FresApiToken auf fres_api_tokens + onDelete-Hint entfernt (kein DB-FK)"
```

---

### Task 3: Repository — `deleteAllForUser`

**Files:**
- Modify: `src/Repository/FresApiTokenRepository.php`

**Interfaces:**
- Consumes: Entity `App\Entity\FresApiToken` mit `ManyToOne`-Feld `user`.
- Produces: `FresApiTokenRepository::deleteAllForUser(int $userId): int` — löscht alle Tokens des Nutzers per DQL-Bulk-Delete, gibt Anzahl gelöschter Zeilen zurück. **Wird von Task 4 und Task 6 aufgerufen.**

- [ ] **Step 1: Methode hinzufügen**

In `src/Repository/FresApiTokenRepository.php` nach der bestehenden `delete()`-Methode (vor der schließenden Klassenklammer) einfügen:

```php
    /**
     * Loescht alle API-Tokens eines Nutzers (Bulk-Delete via DQL).
     * Ersetzt das durch den fehlenden FK weggefallene ON DELETE CASCADE.
     *
     * @return int Anzahl geloeschter Zeilen
     */
    public function deleteAllForUser(int $userId): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\FresApiToken t WHERE t.user = :userId')
            ->setParameter('userId', $userId)
            ->execute();
    }
```

- [ ] **Step 2: Syntax prüfen**

Run: `php -l src/Repository/FresApiTokenRepository.php`
Expected: `No syntax errors detected in src/Repository/FresApiTokenRepository.php`

- [ ] **Step 3: DQL gegen das Mapping validieren (kein DB-Zugriff nötig für Parse-Check)**

Run: `php bin/console cache:clear --env=dev`
Expected: erfolgreich (kein Mapping-Fehler). Die DQL-Klasse/-Feldnamen (`App\Entity\FresApiToken`, `t.user`) entsprechen dem Entity-Mapping aus Task 2.

- [ ] **Step 4: Commit**

```bash
git add src/Repository/FresApiTokenRepository.php
git commit -m "feat(repo): deleteAllForUser fuer aktive Token-Bereinigung"
```

---

### Task 4: Token-Bereinigung beim Löschen eines Nutzers

**Files:**
- Modify: `src/Entities/Users.php` (Import oben + Methode `DeleteUser` bei `:117`)

**Interfaces:**
- Consumes: `FresApiTokenRepository::deleteAllForUser(int)` aus Task 3.
- Produces: `Users::DeleteUser` räumt nach dem Soft-Delete die Tokens des Nutzers ab.

- [ ] **Step 1: Import ergänzen**

In `src/Entities/Users.php` zu den bestehenden `use`-Zeilen hinzufügen:

```php
use App\Entity\FresApiToken;
```

- [ ] **Step 2: Bereinigung nach dem Flush aufrufen**

In `Users::DeleteUser` die bestehende Sequenz

```php
      $em->persist($user);
      $em->flush();
    }
  }
```

(am Ende der Methode, innerhalb des `if ($user)`-Blocks) ersetzen durch

```php
      $em->persist($user);
      $em->flush();

      // FK-loses Schema: Tokens des Nutzers aktiv entfernen (ersetzt ON DELETE CASCADE)
      $em->getRepository(FresApiToken::class)->deleteAllForUser((int) $id);
    }
  }
```

- [ ] **Step 3: Syntax prüfen**

Run: `php -l src/Entities/Users.php`
Expected: `No syntax errors detected in src/Entities/Users.php`

- [ ] **Step 4: Container compilieren**

Run: `php bin/console cache:clear --env=dev`
Expected: erfolgreich.

- [ ] **Step 5: Commit**

```bash
git add src/Entities/Users.php
git commit -m "feat: Tokens beim Loeschen eines Nutzers aktiv entfernen"
```

---

### Task 5: Lock-Transition-Regel als testbare Hilfsfunktion (TDD)

**Files:**
- Create: `tests/Entities/UsersTest.php`
- Modify: `src/Entities/Users.php` (neue statische Methode)

**Interfaces:**
- Produces: `Users::isNewlyLocked(bool $wasLocked, bool $isNowLocked): bool` — `true` genau dann, wenn ein Übergang entsperrt→gesperrt vorliegt. **Wird von Task 6 verwendet.**

- [ ] **Step 1: Failing Test schreiben**

Neue Datei `tests/Entities/UsersTest.php`:

```php
<?php
namespace App\Tests\Entities;

use App\Entities\Users;
use PHPUnit\Framework\TestCase;

class UsersTest extends TestCase
{
    public function testIsNewlyLockedTrueOnlyOnUnlockedToLockedTransition(): void
    {
        $this->assertTrue(Users::isNewlyLocked(false, true));
    }

    public function testIsNewlyLockedFalseWhenAlreadyLocked(): void
    {
        $this->assertFalse(Users::isNewlyLocked(true, true));
    }

    public function testIsNewlyLockedFalseWhenUnlocked(): void
    {
        $this->assertFalse(Users::isNewlyLocked(false, false));
    }

    public function testIsNewlyLockedFalseOnUnlockTransition(): void
    {
        $this->assertFalse(Users::isNewlyLocked(true, false));
    }
}
```

- [ ] **Step 2: Test ausführen — muss fehlschlagen**

Run: `php vendor/bin/phpunit --filter UsersTest`
Expected: FAIL — `Error: Call to undefined method App\Entities\Users::isNewlyLocked()`.

- [ ] **Step 3: Minimale Implementierung**

In `src/Entities/Users.php` direkt nach der bestehenden `public static function isLocked(...)`-Methode einfügen:

```php
  /**
   * True, wenn ein Nutzer beim Speichern von "entsperrt" auf "gesperrt"
   * wechselt. Verhindert wiederholtes Aufraeumen bei jedem Save eines
   * bereits gesperrten Nutzers.
   */
  public static function isNewlyLocked(bool $wasLocked, bool $isNowLocked): bool
  {
    return !$wasLocked && $isNowLocked;
  }
```

- [ ] **Step 4: Test ausführen — muss bestehen**

Run: `php vendor/bin/phpunit --filter UsersTest`
Expected: PASS (4 Tests, 4 Assertions).

- [ ] **Step 5: Commit**

```bash
git add tests/Entities/UsersTest.php src/Entities/Users.php
git commit -m "feat: Users::isNewlyLocked + Tests fuer Lock-Transition-Regel"
```

---

### Task 6: Token-Bereinigung beim Sperren eines Nutzers (SaveAction)

**Files:**
- Modify: `src/Controller/EditUserController.php` (Import oben + `SaveAction` bei `:215`)

**Interfaces:**
- Consumes: `Users::isNewlyLocked(bool, bool)` (Task 5), `FresApiTokenRepository::deleteAllForUser(int)` (Task 3), `Users::isLocked` (bestehend).
- Produces: SaveAction räumt Tokens genau beim Übergang entsperrt→gesperrt ab.

- [ ] **Step 1: Import ergänzen**

In `src/Controller/EditUserController.php` zu den bestehenden `use`-Zeilen hinzufügen:

```php
use App\Entity\FresApiToken;
```

- [ ] **Step 2: Lock-Zustand vor dem Form-Binding erfassen**

In `SaveAction` direkt **nach** dem if/else-Block, der `$user` lädt bzw. erzeugt (also nach der Zeile `$user = $this->CreateUser($request, $loggedin_user);` samt schließender `}`), und **vor** `$form = $this->BuildForm(...)`, einfügen:

```php
    // Lock-Zustand VOR dem Form-Binding festhalten (fuer Transition-Erkennung)
    $wasLocked = ($userid != 0) ? Users::isLocked($user) : false;
```

- [ ] **Step 3: Nach dem Flush bei Übergang entsperrt→gesperrt aufräumen**

Im erfolgreichen Speicherzweig die bestehende Sequenz

```php
      $em->persist($user);
      $em->flush();
      
      return $this->redirect($sd->GetBookingDetailBackRoute());
```

ersetzen durch

```php
      $em->persist($user);
      $em->flush();

      // Beim Uebergang entsperrt -> gesperrt: Tokens des Nutzers aktiv entfernen
      if (Users::isNewlyLocked($wasLocked, Users::isLocked($user))) {
        $em->getRepository(FresApiToken::class)->deleteAllForUser((int) $user->getId());
      }

      return $this->redirect($sd->GetBookingDetailBackRoute());
```

- [ ] **Step 4: Syntax prüfen**

Run: `php -l src/Controller/EditUserController.php`
Expected: `No syntax errors detected in src/Controller/EditUserController.php`

- [ ] **Step 5: Container compilieren**

Run: `php bin/console cache:clear --env=dev`
Expected: erfolgreich.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/EditUserController.php
git commit -m "feat: Tokens beim Sperren eines Nutzers aktiv entfernen (Transition-Detection)"
```

---

### Task 7: Gesamtverifikation

**Files:** keine.

- [ ] **Step 1: Komplette Testsuite läuft grün**

Run: `php vendor/bin/phpunit`
Expected: PASS — alle bestehenden Tests (ApiTokenService, BearerTokenAuthenticator) plus die neuen `UsersTest` ohne Fehler/Failures.

- [ ] **Step 2: Container-Compile final**

Run: `php bin/console cache:clear --env=dev`
Expected: `[OK]` ohne Mapping-/Annotation-Fehler.

- [ ] **Step 3: Manuelle Smoke-Verifikation dokumentieren (gegen Test-/lokale DB durch Oliver)**

Da kein DB-Integrationstest-Harness existiert, wird das DB-Verhalten bewusst manuell verifiziert:
1. `sql/2026-06-26-api-tokens.sql` gegen die DB einspielen → läuft ohne „Failed to open the referenced table" durch.
2. Für einen Test-Nutzer ein Token anlegen, den Nutzer im Admin-UI sperren (`islocked`) und speichern → die Token-Zeile(n) des Nutzers sind entfernt.
3. Erneutes Speichern des bereits gesperrten Nutzers → keine weitere DB-Aktivität/Fehler (Transition-Detection greift).
4. Token anlegen, Nutzer löschen → Token-Zeile(n) entfernt.

Diese Schritte führt Oliver bewusst gegen die richtige Datenbank aus; sie sind nicht Teil der automatisierten Suite.
