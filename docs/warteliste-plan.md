# Umsetzungsplan – Warteliste bei Buchungen

**Stand:** 2026-07-17 · FlyRes (Symfony 8.1, Doctrine ORM 3, mandantenfähig)
**Modell:** Cron-basiertes **Direkt-Nachrücken** (Auto-Buchen, ohne Bestätigungsfrist)

---

## Leitentscheidungen

- Ein Wartelisten-Eintrag ist eine **Buchung mit `status = 'warteliste'`** – gleiches Flugzeug, Zeitfenster, Pilot, Zweck. Reihenfolge über `createddate` (keine Positions-Spalte).
- **Nachrücken = direkte Übernahme:** Wird ein Slot frei, schaltet der Cron den ältesten passenden Eintrag von `warteliste` **direkt auf `0`** (echte Buchung) – **dieselbe Zeile**, kein neuer Datensatz, keine Bestätigung, keine Frist.
- **Kernregel:** *Warteliste belegt nichts.* Ein Wunsch blockiert keinen Slot; wird er zur Buchung, blockiert er wie jede andere.
- **Nachrücken passiert per Cron**, nicht in der Storno-Sekunde.
- **Kein neues Composer-Paket, keine DB-Migration nötig** – das `status`-Feld (`string(30)`) existiert bereits. → einfachster möglicher Deploy.

**Bewusste Kompromisse dieser Variante:**
1. *Kein sekundengenauer Vorrang* – zwischen Storno und nächstem Cron-Lauf könnte ein Direkt-Bucher den Slot wegschnappen (Vorrang „innerhalb des Cron-Intervalls").
2. *Der Nachrücker ist danach fest gebucht* – passt es nicht, muss er aktiv stornieren. Die „nachgerückt"-Mail muss das klar sagen.

---

## Phase 0 · Status-Wert (kein DB-Change)

**Ziel:** den neuen Zustand festlegen. **Keine Migration nötig.**

Status-Werte im bestehenden Feld `FRes_booking.status`: bestehend `0`, `storniert`, `flugzeug_geloescht`, `user_geloescht`; **neu** nur `warteliste`.
Kein neues Feld, kein `ALTER TABLE`, keine Entity-Änderung.

---

## Phase 1 · Der zentrale Filter (Fundament – kleine Änderung, große Wirkung)

**Ziel:** Warteliste-Wünsche zählen nirgends als belegt.

| Datei | Änderung |
|---|---|
| `src/Entities/Bookings.php:42-44` | `ACTIVE_STATUS_*` um `AND status <> 'warteliste'` erweitern (alle drei Varianten DQL/DQL_A/SQL). |
| `src/Entities/Bookings.php` | Neue Konstanten `WAITLIST_STATUS_DQL/SQL` = `status = 'warteliste'` für Anzeige-Queries. |
| `src/Controller/Api/FleetController.php:94-96` | Hartkodierten Status-Filter auf `ACTIVE_STATUS_SQL` umstellen (sonst rutscht `warteliste` in die Flotten-Ampel). |

**Wirkung automatisch in:** `IsPlaneAvailable` (Konflikt), `GetBookingsForAllPlanes` (Ampel), `GetBookingsForPlaneAndDate` (Tag), `GetBookingsForGeneralView` (Liste), `GetFreeGaps*` (Lücken).

**Test:** `IsPlaneAvailable` ignoriert `warteliste`, blockt `0`.

---

## Phase 2 · Buchen – Konfliktzweig & Verlassen

**Ziel:** Bei belegtem Slot die Option „auf Warteliste"; Eintrag wieder entfernen können.

| Datei | Änderung |
|---|---|
| `src/Controller/Api/BookingController.php:290` (`applyAndValidate`) | Bei Overlap-Konflikt: Request-Flag `waitlist=true` → Buchung mit `status='warteliste'` anlegen (statt Fehler). Ohne Flag wie bisher Fehler **plus** Hinweis „Slot belegt – Warteliste möglich". |
| `src/Controller/Api/BookingController.php:146` (`create`) | Wartelisten-Eintrag: **keine** normale Buchungsmail, sondern „auf Warteliste eingetragen" (Phase 4). |
| `BookingController::delete` / `Bookings::DeleteBooking:1118` | „Warteliste verlassen" = Storno des `warteliste`-Eintrags (funktioniert bereits über den Soft-Delete-Pfad). |

**Rechte:** wie normale Buchung (`ROLE_PILOT`), keine neue Voter-Logik.

---

## Phase 3 · Der Cron `app:waitlist:process` (ein Schritt)

**Ziel:** verzögertes Direkt-Nachrücken.

| Datei | Änderung |
|---|---|
| `src/Command/ProcessWaitlistCommand.php` (neu) | Command `app:waitlist:process`. Pro Mandant, je `warteliste`-Eintrag (FIFO nach `createddate`): vergangene Slots (`itemstop < now`) verfallen lassen; sonst wenn `IsPlaneAvailable` → `setStatus(0)` + „nachgerückt"-Mail. |
| `src/Entities/Bookings.php` | Helper `GetWaitlistEntries($em,$clientid)`, optional `GetWaitlistCountsForRange(...)` für die Übersichten. |

**Cron-Logik:**
```
foreach clientid:
  foreach (status='warteliste' ORDER BY createddate ASC):
     if itemstop < now:        → verfallen (Slot vorbei)
     else if IsPlaneAvailable: → setStatus(0)  +  "nachgerückt"-Mail (inkl. .ics)
```
Kein zweiter Schritt, keine Zustandsmaschine, keine Frist.

**Test:** Command mit Beispieldaten (Blocker stornieren → Cron bucht ältesten Wartenden).

---

## Phase 4 · Mails

**Ziel:** die zwei Kommunikationsmomente.

| Datei | Änderung |
|---|---|
| `src/Entities/Bookings.php:1208` (`SendBookingsInfoMail`) | Neue Typen: `warteliste_eingetragen` („du stehst auf der Warteliste") und `warteliste_nachgerueckt` („du bist fest gebucht – wenn's nicht passt, bitte stornieren"). |
| `templates/emails/bookingmail.html.twig` | Betreff/Textbausteine je Typ. `nachgerueckt` erzeugt `.ics` wie eine normale Buchung. |

---

## Phase 5 · Übersichten (modernes Web)

**Ziel:** getrennte Darstellung gemäß Mockup (ohne „Angebot läuft").

| Ansicht / Datei | Änderung |
|---|---|
| Ampel · `GetBookingsForAllPlanes:868` + `overview` Twig | Belegung unverändert (Filter erledigt es). Optional: Nachfrage-Zähler an der Zellenecke. |
| Tagesplan · `GetBookingsForPlaneAndDate:1071` + `day.html.twig` | Fußzeile „X warten" pro Slot. |
| Buchungsliste · `GetBookingsForGeneralView:576` + `bookings.html.twig` | Neuer Reiter „Warteliste" (neuer `$command`-Wert) mit Zähler-Badge; „Meine"/„Alle" bleiben ohne Warteliste. |
| Dashboard · `ModernPreviewController` Stats | Wartende **nicht** als Buchung zählen; optional Kennzahl „X Wartende". |

---

## Phase 6 · PWA (Vue)

| Datei | Änderung |
|---|---|
| `frontend/src/api.js` | Calls: Warteliste-Liste, `join` (Konflikt→Warteliste), `leave`. |
| `components/BookingForm.vue` | Konflikt-Dialog „Slot belegt – auf Warteliste?". |
| `views/MyFlightsView.vue` | Abschnitt „Meine Wartelistenplätze" (Wartend mit Position, „Entfernen"). |
| `components/DetailSheet.vue` / `BookingCard.vue` | Badge „Warteliste". |
| `views/FleetView.vue` | optionaler Nachfrage-Indikator. |

**Build:** `cd frontend && npm run build` → `public/mobile/` hochladen (Vite 7).

---

## Phase 7 · Betrieb & Test

- **Cron einrichten:** Plesk → Geplante Aufgaben → `php …/bin/console app:waitlist:process`, alle 10 Min (`*/10 * * * *`).
- **End-to-End:** eintragen → Blocker-Buchung stornieren → Cron → ältester wird gebucht + Mail. Sowie: Slot in der Vergangenheit → verfällt.
- **Tests lokal:** `php vendor/bin/phpunit` (nicht `bin/phpunit` – Bridge-Cache inkompatibel mit PHP 8.5).

---

## Reihenfolge

1. **Fundament:** Phase 0 + 1 (klein, entscheidend).
2. **Funktionaler Kern:** Phase 2 → 3 → 4. **Danach ist das Feature end-to-end nutzbar.**
3. **Feinschliff:** Phase 5 (Web) + 6 (PWA).
4. **Betrieb:** Phase 7.

**Spätere Ausbaustufe (optional):** Angebot mit Bestätigungsfrist statt Auto-Buchen – dann zusätzlich Status `warteliste_angeboten`, Spalte `angeboten_bis`, Bestätigungs-/Ablehnen-Route und die Frist-Mails. Bewusst als Upgrade offen gehalten.

---

## Offene Detailentscheidungen

- **Cron-Intervall:** Vorschlag 10 Min (kürzer = kleineres Vorrang-Fenster, siehe Kompromiss 1).
- **Nachfrage-Zähler in der Ampel-Matrix:** ja/nein? (nur Tagesplan wäre „sauberer")
- **Mehrfach-Einträge:** darf dieselbe Person mehrere Wartelisten-Wünsche parallel haben? (Vorschlag: ja)
- **Wording der „nachgerückt"-Mail:** muss den festen Buchungscharakter + Storno-Hinweis klar machen.

---

## Deploy-Zusammenfassung (ohne Server-Console)

1. **Dateien** (`src/`, `config/`, ggf. `templates/`, `public/mobile/`) per FTP – **entfernte Dateien auf dem Server nicht vergessen** (FTP löscht nicht).
2. **`var/cache/prod/` leeren** im Dateimanager.
3. **PHP-FPM auffrischen** (Plesk PHP-Einstellungen speichern).
4. **Cron** als Plesk-Geplante-Aufgabe anlegen.

**Kein `composer install`, keine SQL-Migration nötig.**

---

## Visuelle Mockups (Artefakte)

- Touchpoints (Konflikt, Meine Wartelistenplätze, Tagesplan, Mail): https://claude.ai/code/artifact/9632e22c-f8fe-4fef-8f6b-0f9e44f660bf
- Übersichten (Ampel, Tagesplan, Buchungsliste): https://claude.ai/code/artifact/017af978-2e70-469f-a4d8-60bf11f6eac6
- Dieser Plan als Artefakt: https://claude.ai/code/artifact/c8c93bca-0f8a-44f8-bbf0-fc6f9fb41b85
