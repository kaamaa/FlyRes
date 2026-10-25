-- tools_airport_timezone_map: korrigiert die Sonnenaufgangs-/Sonnenuntergangs-Zeiten
-- fuer Laender mit mehreren Zeitzonen (USA, Russland, Kanada, Australien, Brasilien,
-- Mexiko, Indonesien, Kasachstan). Bisher nutzte Sunrise_SunsetController eine einzige
-- Zeitzone pro Land aus tools_countries.Timezone, obwohl z.B. die USA 6+ Zeitzonen haben.
--
-- tools_airports.TIME enthaelt pro Flugplatz bereits den korrekten UTC-Standard-/
-- Sommerzeit-Offset (z.B. "UTC-6(-5DT)" fuer Central Time), wurde aber bislang nur fuer
-- eine Anzeige-Fusszeile geparst, nie fuer die eigentliche Tabellenberechnung benutzt.
-- Diese Tabelle bildet (Country_Code, TIME-Muster) -> echte IANA-Zeitzone ab, damit PHP
-- die Sommerzeit-Umstelltermine automatisch und korrekt aus der Zeitzonendatenbank zieht
-- (keine hartkodierten Umstelldaten noetig).
--
-- Manuell ausfuehren: mysql -u <user> -p <database> < sql/2026-10-25-airport-timezone-map.sql
--
-- Deckt die haeufigsten TIME-Muster ab (Stand 2026-10-25, ermittelt per Datenabgleich).
-- Einzelne sehr selten vorkommende Muster (<=2 Flugplaetze) sind mit geringerer
-- Konfidenz zugeordnet (siehe Kommentare) - bei Bedarf spaeter per Hand nachschaerfen.
-- Flugplaetze ohne TIME-Wert (~44% der US-Flugplaetze) fallen weiterhin auf
-- tools_countries.Timezone zurueck, bis Schritt 2 (Geo-Lookup ueber sLat/sLong) folgt.

CREATE TABLE IF NOT EXISTS tools_airport_timezone_map (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    Country_Code   VARCHAR(3)    NOT NULL,
    Time_Pattern   VARCHAR(40)   NOT NULL COMMENT 'normalisiert: keine Leerzeichen, exakt wie tools_airports.TIME nach Entfernen aller Spaces',
    Timezone       VARCHAR(40)   NOT NULL COMMENT 'IANA-Zeitzonenname, z.B. America/Chicago',
    Confidence     VARCHAR(10)   NOT NULL DEFAULT 'high' COMMENT 'high = eindeutig belegt, low = seltenes Einzelmuster, ggf. pruefen',
    PRIMARY KEY (id),
    UNIQUE KEY uk_country_pattern (Country_Code, Time_Pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tools_airport_timezone_map (Country_Code, Time_Pattern, Timezone, Confidence) VALUES
-- USA (Festland-Zeitzonen + Hawaii/Alaska/Samoa, Arizona ohne Sommerzeit)
('US', 'UTC-5(-4DT)', 'America/New_York',    'high'),
('US', 'UTC-6(-5DT)', 'America/Chicago',     'high'),
('US', 'UTC-7(-6DT)', 'America/Denver',      'high'),
('US', 'UTC-8(-7DT)', 'America/Los_Angeles', 'high'),
('US', 'UTC-7',       'America/Phoenix',     'high'), -- Arizona beobachtet keine Sommerzeit
('US', 'UTC-5',       'America/New_York',    'low'),  -- heute beobachtet ganz Eastern Time DST; vermutlich veraltete/fehlerhafte Rohdaten
('US', 'UTC-10',      'Pacific/Honolulu',    'high'), -- Hawaii, keine Sommerzeit
('US', 'UTC-11',      'Pacific/Pago_Pago',   'high'), -- Amerikanisch-Samoa, keine Sommerzeit

-- Kanada
('CA', 'UTC-5(-4DT)', 'America/Toronto',     'high'),
('CA', 'UTC-4(-3DT)', 'America/Halifax',     'high'),
('CA', 'UTC-8(-7DT)', 'America/Vancouver',   'high'),
('CA', 'UTC-7(-6DT)', 'America/Edmonton',    'high'),
('CA', 'UTC-6(-5DT)', 'America/Winnipeg',    'high'),
('CA', 'UTC-6',       'America/Regina',      'high'), -- Saskatchewan beobachtet keine Sommerzeit
('CA', 'UTC-5',       'America/Toronto',     'low'),  -- Einzelfall, ggf. Nordwest-Ontario (Central ohne DST)

-- Australien (Queensland, Westaustralien und Northern Territory ohne Sommerzeit)
('AS', 'UTC+10(+11DT)',  'Australia/Sydney',   'high'),
('AS', 'UTC+10',         'Australia/Brisbane', 'high'),
('AS', 'UTC+8',          'Australia/Perth',    'high'),
('AS', 'UTC+9:30(+10:30DT)', 'Australia/Adelaide', 'high'),
('AS', 'UTC+9:30',       'Australia/Darwin',   'high'),
('AS', 'UTC+8(+9DT)',    'Australia/Perth',    'low'),  -- WA testete DST nur historisch, Einzelfall
('AS', 'UTC+9.30',       'Australia/Darwin',   'low'),  -- Tippfehler-Variante (Punkt statt Doppelpunkt) von UTC+9:30

-- Russland (Kaliningrad bis Kamtschatka; Russland kennt seit 2014 keine Sommerzeit mehr -
-- der "(+XDT)"-Anteil in den Rohdaten ist veraltet, die IANA-Zone beruecksichtigt das korrekt)
('RS', 'UTC+2(+3DT)',  'Europe/Kaliningrad', 'high'),
('RS', 'UTC+3(+4DT)',  'Europe/Moscow',      'high'),
('RS', 'UTC+4(+5DT)',  'Europe/Samara',      'high'),
('RS', 'UTC+5(+6DT)',  'Asia/Yekaterinburg', 'high'),
('RS', 'UTC+6(+7DT)',  'Asia/Omsk',          'high'),
('RS', 'UTC+7(+8DT)',  'Asia/Krasnoyarsk',   'high'),
('RS', 'UTC+8(+9DT)',  'Asia/Irkutsk',       'high'),
('RS', 'UTC+9(+10DT)', 'Asia/Yakutsk',       'high'),
('RS', 'UTC+10(+11DT)','Asia/Vladivostok',   'high'),
('RS', 'UTC+11(+12DT)','Asia/Magadan',       'high'),
('RS', 'UTC+12(+13DT)','Asia/Kamchatka',     'high'),

-- Brasilien (Brasilien kennt seit 2019 keine Sommerzeit mehr)
('BR', 'UTC-3(-2DT)', 'America/Sao_Paulo',   'high'),
('BR', 'UTC-4(-3DT)', 'America/Manaus',      'high'),
('BR', 'UTC-5(-4DT)', 'America/Rio_Branco',  'high'),
('BR', 'UTC-3',       'America/Fortaleza',   'low'),  -- Nordost-Brasilien, nie Sommerzeit; Einzelfall
('BR', 'UTC-2(-1DT)', 'America/Noronha',     'low'),  -- Fernando de Noronha, Einzelfall

-- Mexiko
('MX', 'UTC-6(-5DT)', 'America/Mexico_City', 'high'),
('MX', 'UTC-7(-6DT)', 'America/Chihuahua',   'high'),
('MX', 'UTC-7',       'America/Hermosillo',  'high'), -- Sonora beobachtet keine Sommerzeit
('MX', 'UTC-8(-7DT)', 'America/Tijuana',     'high'),
('MX', 'UTC-5(-4DT)', 'America/Cancun',      'low'),  -- Quintana Roo ist seit 2015 fix UTC-5 ohne DST; Rohdaten wirken veraltet

-- Indonesien
('ID', 'UTC+7', 'Asia/Jakarta',  'high'),
('ID', 'UTC+8', 'Asia/Makassar', 'high'),
('ID', 'UTC+9', 'Asia/Jayapura', 'high'),

-- Kasachstan (seit 2024 landesweit eine Zeitzone; Rohdaten spiegeln den alten Stand)
('KZ', 'UTC+6',      'Asia/Almaty', 'high'),
('KZ', 'UTC+5',      'Asia/Aqtobe', 'high'),
('KZ', 'UTC+6(+7DT)','Asia/Almaty', 'low');
