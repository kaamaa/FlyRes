# FlyRes iPhone-PWA – Umsetzungsplan

## Ziel
Eine iPhone-optimierte Web-App (PWA) für die drei Kernfunktionen:
1. **Reservieren** – inkl. Tagesübersicht „wann sind Flugzeug + Fluglehrer frei?"
2. **Meine Flüge** – eigene Reservierungen als Karten-Liste
3. **Alle Reservierungen** – mit Filter (Zeitraum, Flugzeug, Fluglehrer, Pilot; jeweils optional)

## Architektur (entschieden)
- **Eine Deployment-Einheit:** Die PWA wird vom bestehenden Symfony-Server ausgeliefert (`public/app/`).
- **Gleiche Domain** → kein CORS, **bestehende Login-Sitzung** wird weitergenutzt.
- **Backend = JSON-API** (`/api/*`) als dünne Schicht über der vorhandenen Logik (`FresBooking`, `Bookings`, `/displayfi`, `SaveAction`).
- **Frontend = Vue 3 + Vite**, als PWA (Manifest + Service Worker, „Zum Home-Bildschirm").

```
Browser (iPhone, PWA)
   │ fetch() JSON, Session-Cookie (credentials: 'include')
   ▼
Symfony 6.4 (FlyRes)
   ├─ /api/*          → JSON-Controller (NEU, dünn)
   ├─ Bookings-Service, Availability-Logik  (BESTEHEND, wiederverwendet)
   ├─ /app/*          → liefert die gebaute PWA (statisch)
   └─ MySQL (FRes_booking …)
```

---

## Phase 1 – Backend: JSON-API

Neue Controller, die `JsonResponse` zurückgeben und die **bestehenden** Services/Repositories nutzen (keine Logik-Duplikate).

### Endpoints
| Methode | Pfad | Zweck | Wiederverwendet |
|---|---|---|---|
| GET | `/api/me` | aktueller Pilot (Name, ID, Rollen) | Security-Token |
| GET | `/api/aircraft` | Flugzeugliste (Dropdowns/Filter) | bestehende Tabelle |
| GET | `/api/instructors` | Fluglehrerliste | bestehende Tabelle |
| GET | `/api/pilots` | Pilotenliste | bestehende Tabelle |
| GET | `/api/bookings?from&to&aircraft&fi&pilot&scope` | Liste/Filter (Meine + Alle) | `Bookings`-Repository |
| GET | `/api/bookings/{id}` | Detail | `GetBookingDetails()` |
| POST | `/api/bookings` | reservieren (mit Validierung + Mailversand) | `SaveAction`-Logik |
| PATCH | `/api/bookings/{id}` | ändern | bestehende Logik |
| DELETE | `/api/bookings/{id}` | löschen (mit Mailbenachrichtigung) | bestehende Logik |
| GET | `/api/availability?date&aircraft&fi` | freie Slots (kombiniert) | `/displayfi`-Logik |

Alle Filterparameter **optional** → leer = kein Filter.

### Beispiel-JSON
**`GET /api/bookings?scope=mine&from=today`**
```json
[
  {
    "id": 4123, "date": "2026-06-25", "start": "10:00", "end": "12:00",
    "aircraft": { "id": 7, "type": "C42", "callsign": "D-MABC" },
    "purpose": "Schulung", "isTraining": true,
    "instructor": { "id": 12, "name": "FI Klein" },
    "pilot": { "id": 3, "name": "M. Weber" },
    "airfield": "EDFV Worms", "description": "Platzrunden",
    "status": "reserved", "canEdit": true
  }
]
```

**`GET /api/availability?date=2026-06-25&aircraft=7&fi=12`**
```json
{
  "date": "2026-06-25", "dayStart": "08:00", "dayEnd": "20:00",
  "freeSlots": [
    { "start": "12:00", "end": "14:00", "minutes": 120 },
    { "start": "15:00", "end": "20:00", "minutes": 300 }
  ],
  "aircraftBusy": [ { "start": "08:00", "end": "10:00" } ],
  "instructorBusy": [ { "start": "08:00", "end": "10:30" } ]
}
```
> Freie Slots = Schnittmenge aus „Flugzeug frei" ∧ „Fluglehrer frei" (belegte `itemstart`/`itemstop` beider zusammenführen, Lücken = freie Slots). Logik serverseitig.

### Auth & Sicherheit
- `security.yaml`: Firewall-Regel für `/api`, gleiches Session-Login wie heute.
- `json_login`-Authenticator ergänzen → die PWA kann sich per JSON-POST anmelden (oder bestehende Login-Seite nutzen).
- **CSRF** für POST/PATCH/DELETE absichern (Token-Header).
- `/api/me` liefert 401, wenn nicht eingeloggt → Frontend zeigt Login.

### Aufwand Phase 1
Klein–mittel. Die Endpoints sind dünn; die schwierige Logik (Verfügbarkeit, Validierung, Mail) ist schon da.

---

## Phase 2 – Frontend: Vue-3-PWA

### Projekt-Setup
- Neues Frontend-Projekt mit **Vite + Vue 3** (eigener Ordner, z.B. `frontend/`).
- `vite-plugin-pwa` für Manifest + Service Worker.
- Build-Ausgabe (`dist/`) wird nach `public/app/` deines Symfony kopiert.

### App-Struktur (aus dem Mockup abgeleitet)
```
App (Tab-Navigation: Reservieren · Meine · Alle)
├─ views/
│   ├─ ReserveView   – Tages-Stepper, Verfügbarkeits-Slots, Buchungsformular
│   ├─ MyFlightsView – Karten-Liste (Kommende/Vergangene)
│   ├─ AllView       – Filterleiste + Filter-Sheet + Tages-gruppierte Liste
│   └─ DetailView    – Reservierungs-Detail (slide-in)
├─ components/
│   ├─ BookingCard, DayHeader, TimeSlot, AvailabilityBar
│   ├─ FilterSheet (Akkordeon: Zeitraum/Flugzeug/Fluglehrer/Pilot)
│   └─ TabBar
├─ api/  – fetch-Wrapper gegen /api/* (credentials: 'include')
└─ store – aktueller Filter, eingeloggter Nutzer, Stammdaten
```

### iPhone-/PWA-Feinheiten
- `manifest.json`: Name „FlyRes", `display: standalone`, Theme-Farbe.
- App-Icons (ein Logo → mehrere Größen, inkl. `apple-touch-icon`).
- Meta: `viewport-fit=cover`, Statusbar-Style, Safe-Area-Insets (Notch).
- Service Worker: App-Shell cachen → schneller Start, Grundfunktion auch bei wackligem Netz.
- „Zum Home-Bildschirm hinzufügen" → eigenes Icon, Vollbild ohne Safari-Leiste.

### Das vorhandene HTML-Mockup
Dient als visuelle Vorlage. Markup/CSS werden in Vue-Komponenten überführt; Beispiel-Daten durch echte API-Aufrufe ersetzt.

### Aufwand Phase 2
Mittel. Das Design steht (Mockup), es geht v.a. um Komponenten + API-Anbindung.

---

## Phase 3 – Veröffentlichen

1. Frontend bauen: `npm run build` → erzeugt statische Dateien.
2. Dateien nach `public/app/` deines FlyRes-Servers kopieren (Teil des bestehenden Deployments).
3. Symfony-Route, die unter `/app` die `index.html` ausliefert (Catch-all für die PWA).
4. **HTTPS** muss aktiv sein (Voraussetzung für Service Worker) – vermutlich bereits vorhanden.
5. Auf dem iPhone testen: Safari öffnen → Teilen → „Zum Home-Bildschirm".

### Voraussetzungen
- **Node.js** auf dem Build-Rechner (nur zum Bauen, nicht auf dem Server).
- HTTPS auf der FlyRes-Domain.
- App-Icon/Logo in guter Auflösung.

---

## Reihenfolge / Meilensteine
1. **M1 – API-Gerüst:** `/api/me`, `/api/aircraft`, `/api/instructors`, `/api/pilots` + Auth/Session. *(Frontend kann sich anmelden & Stammdaten laden.)*
2. **M2 – Lesen:** `/api/bookings` (Liste + Filter) und `/api/bookings/{id}`. → **Meine Flüge** und **Alle** funktionieren live.
3. **M3 – Verfügbarkeit:** `/api/availability`. → Reservieren-Übersicht mit echten freien Slots.
4. **M4 – Schreiben:** `POST`/`PATCH`/`DELETE`. → Reservieren/Ändern/Löschen komplett.
5. **M5 – PWA-Feinschliff & Deploy:** Icons, Service Worker, Home-Screen, Test am iPhone.

Jeder Meilenstein ist für sich nutzbar/testbar.

---

## Offene Detailpunkte (später)
- Achsen-Zeitfenster der Verfügbarkeit: fix 08–20 Uhr oder dynamisch?
- Filter merken (z.B. „immer nur meine Schulungen") oder neutral starten?
- Flugzeug/Lehrer beim Reservieren mit zuletzt genutzten vorbelegen?
- Push-Benachrichtigungen (z.B. Erinnerung vor dem Flug) – optionale Ausbaustufe.
