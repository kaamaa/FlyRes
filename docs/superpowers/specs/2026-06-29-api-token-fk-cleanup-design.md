# Design: API-Token Schema-Korrektur (FK entfernen, Casing) + aktive Token-Bereinigung

**Datum:** 2026-06-29
**Branch:** `feature/bearer-token-auth`
**Kontext:** Reaktion auf Maintainer-Feedback (kaamaa) zum Bearer-Token-PR.

## Problem

Beim Einspielen des PR-SQL gegen das echte Produktivschema sind zwei Themen aufgetreten:

1. **Casing:** Der PR verwendet `FRes_api_tokens` und referenziert `FRes_accounts`
   (Groß-`FR`). Die Tabellen-Konvention im Produktivschema ist durchgängig
   kleingeschrieben (`fres_`). Funktional unkritisch, weil die DB mit
   `lower_case_table_names=1` läuft (deshalb funktionieren auch die bestehenden
   Entities mit `FRes_`-Mapping) — aber das Script soll dem echten Namen folgen.

2. **Foreign Key:** `fres_accounts` ist eine **MyISAM**-Tabelle. Eine InnoDB-Tabelle
   kann keinen Foreign Key auf eine MyISAM-Tabelle anlegen → Fehler „Failed to open
   the referenced table". Das Schema ist gemischt (MyISAM/InnoDB); der PR-SQL nahm
   fälschlich an, dass das FK-Ziel InnoDB ist.

## Entscheidungen

- **FK entfernen** (statt Kern-Tabelle auf InnoDB umzustellen). Das Umstellen einer
  produktiven MyISAM-Kerntabelle auf InnoDB ist risikoreich (MyISAM-Eigenheiten,
  evtl. FULLTEXT-Indizes) und nicht gerechtfertigt. Der Index `idx_user_last_used`
  auf `user_id` bleibt für Performance erhalten.
- **Casing vollständig kleinschreiben** — sowohl im SQL-Script als auch in der
  Entity-Annotation (`@ORM\Table(name=...)`), passend zum echten DB-Namen.
- **Verlust von `ON DELETE CASCADE` aktiv kompensieren** durch Token-Bereinigung im
  Code (gewählt statt „nichts tun"), damit keine verwaisten Token-Zeilen
  zurückbleiben.

## Sicherheits-Kontext (wichtig)

Der Bearer-Authenticator lehnt gesperrte und gelöschte Nutzer bereits bei **jedem**
Request ab (`TokenController.php:61`, `Users::isLocked` / `Users::isDeleted`). Die
Token eines gesperrten/gelöschten Nutzers sind also bereits funktional tot. Die
aktive Bereinigung schließt **keine Sicherheitslücke**, sondern entfernt die nun
verwaisten Zeilen (Hygiene + sofortiges Entfernen der Datensätze).

## Lösungsansatz

Gewählt: **Repository-Bulk-Delete, aufgerufen aus den bestehenden Sperr-/Lösch-Pfaden.**
(Verworfen: Doctrine-Event-Listener auf der großen Legacy-Entity `FresAccounts` —
schwerer testbar, feuert bei unrelated Saves; DB-Trigger/Cron-Job — Overkill,
führt DB-Logik wieder ein.)

## Änderungen im Detail

### 1. SQL — `sql/2026-06-26-api-tokens.sql`
- Tabellenname → `fres_api_tokens` (auch im Header-Kommentar).
- Den kompletten `CONSTRAINT fk_api_tokens_user … FOREIGN KEY … ON DELETE CASCADE`
  Block entfernen.
- `UNIQUE KEY uk_token_hash` und `KEY idx_user_last_used (user_id, last_used_at)`
  bleiben. Engine bleibt `InnoDB`.

### 2. Entity — `src/Entity/FresApiToken.php`
- `@ORM\Table(name="fres_api_tokens")` (kleingeschrieben).
- `onDelete="CASCADE"` aus dem `@ORM\JoinColumn` entfernen, damit das Mapping die
  FK-lose Realität widerspiegelt. Die `@ORM\ManyToOne`-Assoziation selbst bleibt
  bestehen (funktioniert auch ohne DB-FK).

### 3. Repository — `src/Repository/FresApiTokenRepository.php`
- Neue Methode `deleteAllForUser(int $userId): int`.
- Implementierung: DQL-Bulk-Delete
  `DELETE FROM App\Entity\FresApiToken t WHERE t.user = :userId`, gibt die Anzahl
  betroffener Zeilen zurück. Kein Laden der Entities nötig.

### 4. Bereinigungs-Hooks
- **Löschen** — `Users::DeleteUser()` (`src/Entities/Users.php:117`):
  Nach dem Soft-Delete-Flush `deleteAllForUser($id)` aufrufen
  (via `$em->getRepository(FresApiToken::class)`).
- **Sperren** — `EditUserController::SaveAction()`:
  Den Lock-Zustand **vor** dem Form-Binding erfassen
  (`$wasLocked = Users::isLocked($user)` direkt nach dem Laden des Users, vor
  `BuildForm`/`handleRequest`). Nach dem Flush prüfen, ob ein Übergang
  **entsperrt → gesperrt** stattgefunden hat
  (`!$wasLocked && Users::isLocked($user)`); nur dann `deleteAllForUser()` aufrufen.
  Transition-Detection vermeidet redundante Purges bei jedem Save eines bereits
  gesperrten Nutzers.

## Tests
- Unit-Test `deleteAllForUser`: löscht ausschließlich die Token des Ziel-Nutzers,
  lässt Token anderer Nutzer unberührt; liefert korrekte Anzahl.
- Test, dass `Users::DeleteUser()` die Bereinigung auslöst.
- Test der Transition-Detection in `SaveAction`: Purge nur beim Übergang
  entsperrt → gesperrt, nicht bei wiederholtem Save eines gesperrten Nutzers.

## Ausführung
Das korrigierte SQL läuft dann direkt durch. Das Ausführen gegen die Produktiv-DB
erfolgt bewusst durch den Maintainer/Oliver gegen die richtige Datenbank — nicht
automatisiert.

## Nicht im Scope
- Umstellung der Engine von `fres_accounts` (verworfen, siehe oben).
- Änderungen an anderen Entities oder deren `FRes_`-Mapping.
