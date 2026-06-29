-- ============================================================================
-- FlyRes – Konvertierung der MyISAM-Tabellen nach InnoDB
-- ----------------------------------------------------------------------------
-- Warum: MyISAM kennt nur Table-Locking (kein Row-Locking) und keine
-- Transaktionen und ist nicht absturzsicher. Fuer die viel beschriebene
-- Buchungstabelle FRes_booking ist InnoDB deutlich robuster und besser bei
-- gleichzeitigen Zugriffen.
--
-- Stand laut System-Diagnose (29.06.2026) waren folgende Tabellen noch MyISAM:
--   FRes_booking (~42k Zeilen), FRes_accounts, FRes_fi_availability,
--   FRes_airfield, FRes_note, FRes_aircraft, FRes_client,
--   FRes_flightpurpose, FRes_function
--
-- Wichtig:
--   * ALTER TABLE ... ENGINE=InnoDB baut die Tabelle EINMAL neu auf
--     (Kopier-Vorgang). Die Tabelle ist dabei kurz schreibgesperrt.
--     Bei FRes_booking sind das wenige Sekunden, der Rest praktisch sofort.
--   * VORHER BACKUP (siehe Schritt 0) und ein ruhiges Zeitfenster waehlen.
--   * Kein Code muss geaendert werden – SELECT/INSERT/UPDATE sind identisch.
--   * Keine FULLTEXT-Indizes und keine Foreign Keys betroffen -> unkritisch.
--   * Der ausfuehrende DB-Benutzer braucht das ALTER-Recht.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 0) BACKUP zuerst! (in der Shell ausfuehren, NICHT in SQL)
-- ----------------------------------------------------------------------------
--   mysqldump -u DEIN_USER -p flyres > flyres-backup-2026-06-29.sql
--
-- Nur die betroffenen Tabellen sichern (schneller), alternativ:
--   mysqldump -u DEIN_USER -p flyres \
--     FRes_booking FRes_accounts FRes_fi_availability FRes_airfield \
--     FRes_note FRes_aircraft FRes_client FRes_flightpurpose FRes_function \
--     > flyres-backup-myisam-2026-06-29.sql


-- ----------------------------------------------------------------------------
-- 1) VORHER PRUEFEN: Welche Tabellen sind aktuell noch MyISAM?
-- ----------------------------------------------------------------------------
SELECT table_name,
       engine,
       table_rows,
       ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb
FROM   information_schema.tables
WHERE  table_schema = DATABASE()
  AND  engine = 'MyISAM'
ORDER  BY (data_length + index_length) DESC;


-- ----------------------------------------------------------------------------
-- 2) WICHTIG ZUERST: strengen sql_mode fuer DIESE Verbindung lockern.
-- ----------------------------------------------------------------------------
-- Einige alte Spalten (z.B. FRes_accounts.validuntil) haben den Default
-- '0000-00-00'. MyISAM hat das toleriert; beim Neuaufbau als InnoDB lehnt der
-- aktuelle sql_mode (NO_ZERO_DATE/STRICT_TRANS_TABLES) das ab
-- ("Invalid default value for 'validuntil'").
--
-- Loesung: den Modus NUR FUER DIESE SESSION leeren. Das aendert nichts dauerhaft
-- am Server (gilt nur fuer deine Verbindung) und laesst die Spalte unveraendert.
--
-- ACHTUNG: Diese Zeile muss in DERSELBEN Verbindung/Ausfuehrung wie die ALTERs
-- laufen. In phpMyAdmin/Adminer: einfach zusammen mit den ALTER-Befehlen in
-- einem Rutsch ("Go"/"Ausfuehren") absenden, nicht einzeln nacheinander.
SET SESSION sql_mode = '';


-- ----------------------------------------------------------------------------
-- 2a) EMPFOHLEN – Dynamisch: passende ALTER-Befehle automatisch erzeugen.
--     Diese Abfrage AENDERT NICHTS, sie gibt nur die exakt richtigen
--     ALTER-Statements (mit korrekter Gross-/Kleinschreibung) aus.
--     Ergebnis-Spalte kopieren und – zusammen mit dem SET SESSION oben –
--     in einem Rutsch ausfuehren.
-- ----------------------------------------------------------------------------
SELECT CONCAT('ALTER TABLE `', table_name, '` ENGINE=InnoDB;') AS sql_befehl
FROM   information_schema.tables
WHERE  table_schema = DATABASE()
  AND  engine = 'MyISAM'
ORDER  BY (data_length + index_length) ASC;   -- klein -> gross, FRes_booking zuletzt


-- ----------------------------------------------------------------------------
-- 2b) ALTERNATIV – Explizit pro Tabelle (zusammen mit dem SET SESSION oben).
--     Reihenfolge bewusst klein -> gross, damit die grosse FRes_booking
--     (und ihr kurzer Schreib-Lock) ganz am Ende kommt.
-- ----------------------------------------------------------------------------
SET SESSION sql_mode = '';
ALTER TABLE `FRes_function`        ENGINE=InnoDB;
ALTER TABLE `FRes_flightpurpose`   ENGINE=InnoDB;
ALTER TABLE `FRes_client`          ENGINE=InnoDB;
ALTER TABLE `FRes_aircraft`        ENGINE=InnoDB;
ALTER TABLE `FRes_note`            ENGINE=InnoDB;
ALTER TABLE `FRes_airfield`        ENGINE=InnoDB;
ALTER TABLE `FRes_accounts`        ENGINE=InnoDB;
ALTER TABLE `FRes_fi_availability` ENGINE=InnoDB;
ALTER TABLE `FRes_booking`         ENGINE=InnoDB;


-- ----------------------------------------------------------------------------
-- 3) NACHHER PRUEFEN: Es darf keine MyISAM-Tabelle mehr uebrig sein.
--    Erwartetes Ergebnis: 0
-- ----------------------------------------------------------------------------
SELECT COUNT(*) AS verbleibende_myisam
FROM   information_schema.tables
WHERE  table_schema = DATABASE()
  AND  engine = 'MyISAM';

-- Optional: kurze Integritaetspruefung der wichtigsten Tabelle
CHECK TABLE `FRes_booking`;


-- ----------------------------------------------------------------------------
-- 4) OPTIONAL (Empfehlung C) – Composite-Index fuer die Uebersichts-Abfrage.
--    Beschleunigt die Bereichsabfrage je Flugzeug (aircraftID + Zeitfenster).
--    Bei ~42k Zeilen geringer, aber sauberer Effekt. Erst NACH InnoDB ausfuehren.
--    Vorher pruefen, dass es den Index noch nicht gibt:
--      SHOW INDEX FROM `FRes_booking`;
-- ----------------------------------------------------------------------------
-- ALTER TABLE `FRes_booking` ADD INDEX idx_aircraft_start (aircraftID, itemstart);
