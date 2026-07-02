-- =====================================================================
-- FlyRes – Lizenztypen: Bezeichnungen & Beschreibungen aktualisiert
-- Stand: Juli 2026
--
-- Reines UPDATE auf FRes_licenceType (keine Strukturänderung, kein DELETE).
-- Import z. B. über phpMyAdmin -> Reiter "SQL" oder "Importieren".
-- WICHTIG: Datei/Import als UTF-8 behandeln (wegen Umlauten ä ö ü ß §).
--
-- Inhaltliche Korrekturen (aus dem Netz verifiziert):
--   * Sprachnachweis Level 4 gilt 4 Jahre (EASA FCL.055) – vorher "3 Jahre".
--   * SEP-/TMG-Verlängerung: Übungsflug mit FLUGLEHRER (FI/CRI) ODER
--     Befähigungsüberprüfung mit Prüfer (FE).
--   * Veraltete Begriffe JAA/JAR -> EASA Part-FCL.
--   * UL bis 600 kg MTOM (seit 2019, inkl. Rettungsgerät).
--   * Zahlreiche Tippfehler korrigiert.
--
-- OFFEN (bewusst NICHT geändert): Bei den ASW-Checkflügen steht im Namen
-- "Gültigkeit 12 Monate", im Text war bisher "alle zwei Jahre". Der Name
-- bleibt hier unangetastet; bitte selbst festlegen, was gilt.
-- =====================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- ---------- Kategorie 1: Medical ----------
UPDATE FRes_licenceType SET
  longname    = '(UL / PPL) Flugtauglichkeitszeugnis Klasse 1 (Berufspiloten)',
  description = 'EASA-Tauglichkeitszeugnis Klasse 1 für Berufspiloten (CPL/ATPL). Ausstellung nur durch ein flugmedizinisches Zentrum (AeMC). Gültigkeit in der Regel 12 Monate (6 Monate ab dem 60. Lebensjahr bzw. im gewerblichen Einpilotenbetrieb mit Passagieren).'
WHERE id = 8;

UPDATE FRes_licenceType SET
  longname    = '(UL / PPL) Flugtauglichkeitszeugnis Klasse 2 (Privatpiloten)',
  description = 'EASA-Tauglichkeitszeugnis Klasse 2 für Privatpiloten (PPL). Ausstellung durch einen Fliegerarzt (AME). Gültigkeit altersabhängig: bis 40 Jahre 60 Monate, 40 bis 50 Jahre 24 Monate, ab 50 Jahre 12 Monate.'
WHERE id = 9;

UPDATE FRes_licenceType SET
  longname    = '(UL / PPL) Flugtauglichkeitszeugnis LAPL (Privatpiloten)',
  description = 'Ärztliches LAPL-Tauglichkeitszeugnis (weniger streng als Klasse 2), gültig für die LAPL. Gültigkeit: bis 40 Jahre 60 Monate, ab 40 Jahre 24 Monate.'
WHERE id = 32;

-- ---------- Kategorie 2: Lizenz ----------
UPDATE FRes_licenceType SET
  longname    = '(PPL) Privatpilotenlizenz PPL(A) nach EASA Part-FCL',
  description = 'Berechtigt zum nichtgewerblichen Führen einmotoriger Kolbenflugzeuge (SEP) bis 2000 kg. EU-weit ohne weitere Prüfung anerkannt, auch für Flüge und Charter im Ausland. Löst die frühere JAR-FCL-Lizenz ab.'
WHERE id = 21;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Privatpilotenlizenz LAPL(A)',
  description = 'Light Aircraft Pilot Licence nach EASA Part-FCL: einmotorige Flugzeuge bis 2000 kg MTOM, maximal 3 Passagiere, nach Sichtflugregeln, gültig innerhalb der EASA-Staaten.'
WHERE id = 20;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Privatpilotenlizenz A nach ICAO (alt)',
  description = 'Historischer PPL(A) (vor dem 1.5.2003). Erlaubt international das Führen in Deutschland registrierter Flugzeuge. Heute durch EASA Part-FCL abgelöst.'
WHERE id = 2;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Privatpilotenlizenz A nach JAR-FCL (alt)',
  description = 'PPL(A) nach dem früheren JAR-FCL (2012 durch EASA Part-FCL ersetzt). Nichtgewerbliches Führen einmotoriger Kolbenflugzeuge bis 2000 kg, EU-weit anerkannt.'
WHERE id = 3;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Privatpilotenlizenz National – PPL-N (alt)',
  description = 'Nur in Deutschland gültige nationale Lizenz für einmotorige Flugzeuge bis 750 kg. Auslaufmodell, nicht EASA-konform.'
WHERE id = 1;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Privatpilotenlizenz National mit Class Rating 2000 (alt)',
  description = 'Nationale PPL-N mit erweiterter Klassenberechtigung bis 2000 kg. Historisch, nicht EASA-konform.'
WHERE id = 12;

UPDATE FRes_licenceType SET
  longname    = '(UL) Sportpilotenlizenz SPL (aerodynamisch / 3-Achs gesteuert)',
  description = 'Die Sportpilotenlizenz (SPL, offiziell Luftfahrerschein für Luftsportgeräteführer) erlaubt das Führen zweisitziger, aerodynamisch (3-Achs) gesteuerter Ultraleichtflugzeuge bis 600 kg MTOM (seit 2019, inklusive Rettungsgerät).'
WHERE id = 4;

-- ---------- Kategorie 3: Class Rating ----------
UPDATE FRes_licenceType SET
  longname    = '(PPL) Klassenberechtigung SEP (Single Engine Piston)',
  description = 'Klassenberechtigung für einmotorige Kolbenflugzeuge (Land) nach EASA Part-FCL. Gültigkeit 24 Monate. Verlängerung entweder per Erfahrungsnachweis mit Fluglehrer (12 Flugstunden in den 12 Monaten vor Ablauf, davon 6 Stunden als PIC und 12 Starts/Landungen, plus 1 Stunde Übungsflug mit Fluglehrer FI/CRI) oder per Befähigungsüberprüfung mit Prüfer (FE).'
WHERE id = 14;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Klassenberechtigung TMG (Touring Motor Glider)',
  description = 'Klassenberechtigung für Reisemotorsegler (TMG) nach EASA Part-FCL. Führen von TMG nach Sichtflugregeln am Tag. Gültigkeit 24 Monate; Verlängerung per Erfahrungsnachweis mit Fluglehrer (FI/CRI) oder per Befähigungsüberprüfung mit Prüfer (FE).'
WHERE id = 13;

-- ---------- Kategorie 4: Übungsflug ----------
UPDATE FRes_licenceType SET
  longname    = '(UL) Übungsflug mit Fluglehrer (Verlängerung UL-Lizenz)',
  description = 'Übungsflug mit Fluglehrer zur Verlängerung der UL-Lizenz (SPL); in Deutschland alle 24 Monate erforderlich.'
WHERE id = 15;

-- ---------- Kategorie 6: Fluglehrerlizenz ----------
UPDATE FRes_licenceType SET
  longname    = '(PPL) Fluglehrerberechtigung FI(A) für LAPL',
  description = 'Lehrberechtigung (Flight Instructor) zur Ausbildung von Flugschülern für die LAPL(A).'
WHERE id = 18;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Fluglehrerberechtigung FI(A) für PPL(A)',
  description = 'Lehrberechtigung (Flight Instructor) zur Ausbildung für den PPL(A) nach EASA Part-FCL.'
WHERE id = 6;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Fluglehrerberechtigung für PPL-N (alt)',
  description = 'Historische Lehrberechtigung für die nationale PPL-N. Auslaufmodell.'
WHERE id = 5;

UPDATE FRes_licenceType SET
  longname    = '(UL) Fluglehrerberechtigung für die UL-Lizenz (SPL)',
  description = 'Lehrberechtigung zur Ausbildung von UL-Flugschülern (Sportpilotenlizenz).'
WHERE id = 7;

-- ---------- Kategorie 7: Sprachnachweis (FCL.055) ----------
UPDATE FRes_licenceType SET
  longname    = '(PPL) Sprachnachweis Deutsch – Level 4 (4 Jahre Gültigkeit)',
  description = 'Sprachnachweis (Language Proficiency) nach EASA FCL.055 – Deutsch, Level 4 (Operational). Gültigkeit 4 Jahre.'
WHERE id = 26;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Sprachnachweis Deutsch – Level 5 (6 Jahre Gültigkeit)',
  description = 'Sprachnachweis (Language Proficiency) nach EASA FCL.055 – Deutsch, Level 5 (Extended). Gültigkeit 6 Jahre.'
WHERE id = 27;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Sprachnachweis Deutsch – Level 6 (unbegrenzte Gültigkeit)',
  description = 'Sprachnachweis (Language Proficiency) nach EASA FCL.055 – Deutsch, Level 6 (Expert). Keine Wiederholung erforderlich.'
WHERE id = 28;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Sprachnachweis Englisch – Level 4 (4 Jahre Gültigkeit)',
  description = 'Sprachnachweis (Language Proficiency) nach EASA FCL.055 – Englisch, Level 4 (Operational). Gültigkeit 4 Jahre.'
WHERE id = 11;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Sprachnachweis Englisch – Level 5 (6 Jahre Gültigkeit)',
  description = 'Sprachnachweis (Language Proficiency) nach EASA FCL.055 – Englisch, Level 5 (Extended). Gültigkeit 6 Jahre.'
WHERE id = 16;

UPDATE FRes_licenceType SET
  longname    = '(PPL) Sprachnachweis Englisch – Level 6 (unbegrenzte Gültigkeit)',
  description = 'Sprachnachweis (Language Proficiency) nach EASA FCL.055 – Englisch, Level 6 (Expert). Keine Wiederholung erforderlich.'
WHERE id = 17;

-- ---------- Kategorie 8: ZÜP ----------
UPDATE FRes_licenceType SET
  longname    = '(PPL) Zuverlässigkeitsüberprüfung (ZÜP)',
  description = 'Zuverlässigkeitsüberprüfung nach § 7 Luftsicherheitsgesetz (LuftSiG). Voraussetzung unter anderem für die Pilotenausbildung und den Zugang zu Sicherheitsbereichen von Flugplätzen; regelmäßige Wiederholung.'
WHERE id = 10;

-- ---------- Kategorie 9: ASW-Checkflug (nur Beschreibung, Name unverändert) ----------
UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Bristell B23 – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit der Lizenz- bzw. Class-Rating-Verlängerung (Übungsflug mit Fluglehrer) zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 35;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Katana – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit der Lizenz- bzw. Class-Rating-Verlängerung (Übungsflug mit Fluglehrer) zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 22;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Sportstar RTC – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit der Lizenz- bzw. Class-Rating-Verlängerung (Übungsflug mit Fluglehrer) zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 33;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Bristell LSA-K – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit dem UL-Übungsflug zur Lizenzverlängerung zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 37;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster C42 – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit dem UL-Übungsflug zur Lizenzverlängerung zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 23;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Dynamic WT9 – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit dem UL-Übungsflug zur Lizenzverlängerung zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 24;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Skyline – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit dem UL-Übungsflug zur Lizenzverlängerung zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 34;

UPDATE FRes_licenceType SET
  description = 'Vereinsinterner Checkflug mit Fluglehrer auf dem Muster Zodiac CH-601 – keine Berechtigung im klassischen Sinne, stellt aber sicher, dass der Pilot mit dem jeweiligen Flugzeugmuster vertraut ist. Kann mit dem UL-Übungsflug zur Lizenzverlängerung zusammengelegt werden. Mindestens drei Platzrunden.'
WHERE id = 25;

-- ---------- Kategorie 10: Sprechfunkzeugnis (nur Beschreibung) ----------
UPDATE FRes_licenceType SET
  description = 'Allgemeines Sprechfunkzeugnis für den Flugfunkdienst (AZF) – Sprechfunk im Instrumenten- und Sichtflug, deutsch und englisch. Unbegrenzt gültig.'
WHERE id = 29;

UPDATE FRes_licenceType SET
  description = 'Beschränkt gültiges Sprechfunkzeugnis für den Flugfunkdienst I (BZF I) – Sichtflug, deutsch und englisch (national und international). Unbegrenzt gültig.'
WHERE id = 31;

UPDATE FRes_licenceType SET
  description = 'Beschränkt gültiges Sprechfunkzeugnis für den Flugfunkdienst II (BZF II) – Sichtflug, nur deutsch, nur in Deutschland. Unbegrenzt gültig.'
WHERE id = 30;

COMMIT;

-- Kontrolle nach dem Import:
-- SELECT id, categoryname, longname FROM FRes_licenceType ORDER BY categoryid, longname;
