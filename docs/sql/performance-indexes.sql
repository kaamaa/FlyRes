-- ============================================================================
-- FlyRes – Index-Optimierung (nach der InnoDB-Migration ausfuehren)
-- ----------------------------------------------------------------------------
-- Ziel: die heissen Abfragen (Uebersicht/Tagesansicht/Reservieren) bekommen
-- passende ZUSAMMENGESETZTE Indizes; im Gegenzug fallen die dadurch
-- ueberfluessigen Einzelindizes weg (weniger Schreib-Overhead, weniger Platz).
--
-- Sicher in Produktion:
--   * Nur Sekundaer-Indizes -> in MySQL 8.0 ohnehin standardmaessig ONLINE
--     (INPLACE, ohne Sperre); eine ALGORITHM/LOCK-Klausel ist nicht noetig.
--     (phpMyAdmins Editor-Linter markiert diese Klausel faelschlich rot -
--      daher hier bewusst weggelassen.)
--   * Add VOR Drop in einem Statement -> es gibt nie eine Luecke ohne Index.
--   * Backup ist hier unkritisch (Indizes sind jederzeit neu aufbaubar),
--     schadet aber nie.
--
-- WICHTIG: fres_booking hat Legacy-Defaults '0000-00-00 00:00:00'
-- (createdDate/itemstart/itemstop). MySQL prueft die Tabellendefinition auch
-- beim Index-ALTER -> unter strict mode "Invalid default value for 'createdDate'".
-- Deshalb wie bei der Engine-Migration: sql_mode fuer DIESE Session leeren und
-- den GANZEN Block in EINEM Rutsch ausfuehren (nicht Zeile fuer Zeile).
-- ============================================================================

SET SESSION sql_mode = '';


-- ----------------------------------------------------------------------------
-- 1) fres_booking  (heisse Tabelle, ~42k Zeilen)
-- ----------------------------------------------------------------------------
-- NEU:
--   idx_client_aircraft_start (clientid, aircraftID, itemstart)
--       -> exakt der Zugriff der Uebersicht/Tagesansicht/Reservierung:
--          clientid=? AND aircraftID=? AND itemstart-Bereich, sortiert -> kein Filesort.
--   idx_client_fi_start (clientid, flightinstructor, itemstart)
--       -> Fluglehrer-Konfliktpruefungen (Buchungen je Fluglehrer + Zeit).
--
-- ENTFERNT (durch die Composites abgedeckt bzw. nutzlos):
--   clientid          -> Praefix von beiden Composites (komplett redundant).
--   aircraftid        -> durch idx_client_aircraft_start abgedeckt (Abfragen
--                        enthalten immer clientid).
--   flightinstructor  -> durch idx_client_fi_start abgedeckt.
--   status            -> sehr geringe Selektivitaet, in den Abfragen nur als
--                        "status <> ..." -> ein Index hilft hier ohnehin nie.
--
-- BLEIBEN (eigenstaendiger Nutzen):
--   PRIMARY, itemstart, itemstop  -> client-weite Datums-Scans (Generalview).
--   flightPurposeID               -> Zweck-Filter (Charter/Schulung/Wartung).
--   createdByUserID               -> "Meine Buchungen".
ALTER TABLE `fres_booking`
  ADD INDEX `idx_client_aircraft_start` (`clientid`,`aircraftID`,`itemstart`),
  ADD INDEX `idx_client_fi_start`       (`clientid`,`flightinstructor`,`itemstart`),
  DROP INDEX `clientid`,
  DROP INDEX `aircraftid`,
  DROP INDEX `flightinstructor`,
  DROP INDEX `status`;


-- ----------------------------------------------------------------------------
-- 2) fres_fi_availability  (klein, ~2.7k Zeilen – Effekt gering, aber sauber)
-- ----------------------------------------------------------------------------
-- NEU:
--   idx_client_fi_start (clientid, flightinstructor, itemstart)
-- ENTFERNT:
--   clientid  -> Praefix des Composites (redundant).
--   status    -> geringe Selektivitaet, nutzlos.
-- BLEIBEN bewusst:
--   PRIMARY, flightinstructor, itemstart, itemstop
--   (flightinstructor bleibt, falls Verfuegbarkeits-Abfragen ohne clientid laufen).
ALTER TABLE `fres_fi_availability`
  ADD INDEX `idx_client_fi_start` (`clientid`,`flightinstructor`,`itemstart`),
  DROP INDEX `clientid`,
  DROP INDEX `status`;


-- ----------------------------------------------------------------------------
-- 3) Kontrolle – resultierende Indizes ansehen
-- ----------------------------------------------------------------------------
SHOW INDEX FROM `fres_booking`;
SHOW INDEX FROM `fres_fi_availability`;


-- ----------------------------------------------------------------------------
-- Hinweis (konservative Variante):
--   Falls es irgendwo eine Abfrage gibt, die `aircraftID` ODER `flightinstructor`
--   OHNE `clientid` filtert (in dieser App nicht der Fall), dann diese beiden
--   DROP-Zeilen oben einfach weglassen – die Composites schaden dann trotzdem nicht.
-- ============================================================================
