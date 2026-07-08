# Rollen & Berechtigungen im modernen Frontend (`/modern`)

Diese Übersicht beschreibt, welche Rolle im `/modern`-Bereich welche Menüpunkte
sieht und welche Funktionen sie aufrufen darf. Stand: siehe Git-Historie.

## Rollen-Hierarchie

Jede höhere Rolle erbt alle Rechte der niedrigeren:

```
Pilot → Fluglehrer → Admin → System-Admin → Global-Admin
```

(Technisch: `ROLE_PILOT` ⊂ `ROLE_FI` ⊂ `ROLE_ADMIN` ⊂ `ROLE_SYSTEM_ADMIN` ⊂ `ROLE_GLOBAL_ADMIN`,
definiert in `config/packages/security.yaml`.)

Alle `/modern`-Seiten setzen mindestens **Anmeldung als Pilot** voraus. Die Firewall
verlangt nur „eingeloggt"; die eigentliche Rollenprüfung erfolgt in den Controllern
über `denyAccessUnlessGranted(...)`. Ausgegraute Menüpunkte sind für niedrigere
Rollen sichtbar, aber weder klickbar noch per direktem URL-Aufruf zugänglich
(serverseitig mit „Zugriff verweigert" geblockt).

## Menüpunkte (Sidebar)

| Menüpunkt | Link sichtbar | Aufrufbar (Route-Schutz) |
|---|---|---|
| 🏠 Dashboard | alle | Pilot |
| ＋ Reservieren | alle | Pilot |
| 📋 Alle Buchungen | alle | Pilot |
| 👤 Meine Buchungen | alle | Pilot |
| 📊 Wochenübersicht | alle | Pilot |
| 🎓 Lizenzen | alle | Pilot |
| 📌 Pinnwand | alle | Pilot |
| 🛩️ Flugzeuge | **nur System-Admin** (sonst ausgegraut) | System-Admin |
| 👥 Nutzer | **nur System-Admin** (sonst ausgegraut) | System-Admin |
| ⚙️ Mandanten | **nur Global-Admin** (sonst ausgegraut) | Global-Admin |
| ❓ Anleitung | alle | Pilot |

## Rollenabhängige Funktionen innerhalb der Seiten

### 🏠 Dashboard
- **Alle:** eigene nächste Buchungen, eigene Lizenzen + Warnbanner, Pinnwand
- **+ Fluglehrer:** Block „Meine Schulungstermine"
- **+ Admin:** Block „Ablaufende Lizenzen (alle Nutzer)" + „Club-Kennzahlen"

### ＋ Reservieren / Buchung bearbeiten
- **Alle:** eigene Reservierung anlegen
- Feld **„Reserviert für"** (im Namen eines anderen) wirkt **nur für Admin+** —
  bei Pilot/Fluglehrer wird die Buchung immer auf den eigenen Account angelegt
  (API-Regel in `Api\BookingController::create`)
- **Bearbeiten** einer Buchung: nur **Ersteller**, **zugewiesener Fluglehrer**
  oder **Admin** — und nur, wenn das Ende **≤ 1 Woche** zurückliegt
  (`Bookings::IsAllowedtoChangeBooking` + `IsBookingDateEditable`)

### 📋 Buchungen / Detailansicht
- **Sehen:** alle (alle Buchungen sind einsehbar)
- Button **Bearbeiten:** Ersteller / zugewiesener FI / Admin **und** Ende ≤ 1 Woche her
- Button **Stornieren:** Ersteller / zugewiesener FI / Admin (ohne Datumsgrenze)

### 📊 Wochenübersicht
- **Alle:** nur Ansicht (Flottenauslastung, 14 Tage / Monat)

### 🎓 Lizenzen
- „Meine": alle
- Chips **„Alle Nutzer"** + **„Abgelaufen"** sowie die Pro-Nutzer-Ansicht
  (`?user=`): **nur Admin+**
- **Neue Lizenz:** alle für sich selbst; **Admin+** für beliebigen Nutzer
  (Nutzer-Auswahl im Formular)
- **Lizenz bearbeiten:** eigene → alle; fremde → **nur Admin+**

### 📌 Pinnwand
- Sehen, „Meine"-Filter, neuen Eintrag anlegen: alle
- Eigene Einträge bearbeiten/löschen: alle (nur eigene)
- **Fremde Einträge bearbeiten/löschen: nur System-Admin**
- „Gültig bis" max. 1 Monat in der Zukunft — **Ausnahme: System-Admin** (unbegrenzt)

### 🛩️ Flugzeuge & Flugzeugtypen — komplett **System-Admin**
- Flugzeuge anlegen/bearbeiten, Status aktiv/inaktiv
- Flugzeugtypen anlegen/bearbeiten inkl. erforderlicher Lizenzen, Typen löschen (Soft-Delete)

### 👥 Nutzer — komplett **System-Admin**
- Rollen nur **unterhalb der eigenen Stufe** vergebbar
  (System-Admin: Priorität < 6; Global-Admin: < 7); höhere bestehende Rollen bleiben erhalten
- FI-Einstellungen erscheinen nur bei Fluglehrer-Nutzern
- Selbstlöschung gesperrt (Löschen = Soft-Delete inkl. Buchungen)

### ⚙️ Mandanten — komplett **Global-Admin**
- Anlegen, bearbeiten, aktivieren/deaktivieren (inkl. Statistik Nutzer/Flugzeuge/Reservierungen)

### ❓ Anleitung
- Für alle sichtbar; die Abschnitte für Fluglehrer / Admin / System-Admin /
  Global-Admin werden **nur bei entsprechender Rolle** eingeblendet

## Zusammenfassung: Was bringt welche Stufe zusätzlich?

- **Pilot:** alles Eigene (buchen, eigene Buchungen/Lizenzen/Pinnwand)
- **Fluglehrer:** zusätzlich Buchungen ändern/stornieren, in denen er als FI
  zugewiesen ist, + Schulungstermine im Dashboard
- **Admin:** alle Buchungen ändern, Lizenzen aller Nutzer, Lizenzen für andere
  anlegen, Admin-Dashboard
- **System-Admin:** zusätzlich Nutzer-, Flugzeug- und Typenverwaltung, fremde
  Pinnwand-Einträge, unbegrenzte Pinnwand-Gültigkeit
- **Global-Admin:** zusätzlich Mandantenverwaltung
