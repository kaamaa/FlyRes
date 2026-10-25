-- Fuegt tools_airports.Timezone hinzu: IANA-Zeitzone pro Flugplatz, per Geo-Lookup aus
-- sLat/sLong ermittelt (app:update-airport-timezones). Das ist die genaueste der drei
-- Zeitzonen-Quellen in Sunrise_SunsetController (vor tools_airport_timezone_map und vor
-- tools_countries.Timezone) und deckt auch Flugplaetze ohne brauchbares TIME-Feld ab.
--
-- Reihenfolge beim Deploy:
--   1. Diese Datei ausfuehren (legt die Spalte an)
--   2. sql/2026-10-25-airport-geo-timezone.sql ausfuehren (befuellt alle 10.612 Flugplaetze,
--      lokal vorab berechnet - kein erneuter Download/Rechenlauf auf dem Produktivserver noetig)
--
-- Manuell ausfuehren: mysql -u <user> -p <database> < sql/2026-10-25-airport-geo-timezone-column.sql

ALTER TABLE tools_airports
  ADD COLUMN Timezone VARCHAR(40) NULL COMMENT 'IANA-Zeitzone, per Geo-Lookup aus sLat/sLong ermittelt' AFTER DAYLIGHT_SAVE;
