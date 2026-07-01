-- =====================================================================
-- Alt-Bestand aufraeumen: aktive Lizenzen geloeschter Piloten soft-loeschen
-- =====================================================================
-- Beim Loeschen eines Piloten wurde bisher der Account auf status='geloescht'
-- gesetzt, seine Lizenzen aber NICHT. Dadurch haengen aktive (status=0)
-- Lizenzen an geloeschten Accounts. Dieses Script setzt genau diese Lizenzen
-- ebenfalls auf 'geloescht' (Soft-Delete – nichts wird wirklich entfernt).
--
-- Die Ursache ist im Code behoben (Users::DeleteUser ->
-- Licenses::DeleteAllLicencesForAUser); dieses Script bereinigt nur den
-- bereits entstandenen Alt-Bestand.
--
-- Ausfuehren in phpMyAdmin auf der jeweiligen Datenbank. Tabellennamen sind
-- gross-/kleinschreibungssensitiv: exakt "FRes_userLicences" / "FRes_accounts".
-- Empfehlung: vorher ein Backup ziehen.
-- =====================================================================

-- Vorschau: wie viele Lizenzen waeren betroffen?
--   SELECT COUNT(*) FROM FRes_userLicences l
--   JOIN FRes_accounts a ON a.id = l.accountid
--   WHERE a.status = 'geloescht' AND (l.status IS NULL OR l.status = '0');

UPDATE FRes_userLicences l
JOIN FRes_accounts a ON a.id = l.accountid
SET l.status = 'geloescht'
WHERE a.status = 'geloescht'
  AND (l.status IS NULL OR l.status = '0');

-- Kontrolle danach (sollte 0 sein):
--   SELECT COUNT(*) FROM FRes_userLicences l
--   JOIN FRes_accounts a ON a.id = l.accountid
--   WHERE a.status = 'geloescht' AND (l.status IS NULL OR l.status = '0');
