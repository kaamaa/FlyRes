# FlyRes JSON-API — Referenz

> Generische Referenz aller `/api/*`-Endpunkte, unabhängig vom konsumierenden Client (PWA, native App, externe Integration).

## Setup

**Base-URL:** abhängig vom Deployment, z. B.
- Produktion: `https://flugschule-worms.de/`
- Lokale Entwicklung: `http://localhost:8000/`

Alle Endpunkte hängen unter dem Pfad `/api/`. Beispiel: `https://flugschule-worms.de/api/me`.

**Content-Type:** Alle Endpunkte liefern `application/json; charset=utf-8`. Alle Schreib-Endpunkte erwarten Request-Bodies als `Content-Type: application/json`.

**Authentifizierung:** Zwei Mechanismen parallel:

| Mechanismus | Wer nutzt das | Header / Cookie |
|---|---|---|
| **Bearer-Token** | Native Apps, externe Skripte | `Authorization: Bearer flyres_…` |
| **Session-Cookie** | Browser/PWA | `Cookie: PHPSESSID=…` (Browser-managed) |

Pro Request wird **eine** der beiden Methoden erwartet. Bearer hat Vorrang, wenn beide gleichzeitig anliegen.

**Token-Lifecycle (Bearer):**

```
POST /api/tokens (user + pass)  ─►  token speichern
       │
       ▼
Jeder weitere Request mit
"Authorization: Bearer <token>"
       │
       ├─ 401 invalid_token        →  Token löschen, neu einloggen
       ├─ 200/4xx normale Antwort
       │
       ▼
DELETE /api/tokens/current        →  Token serverseitig invalidieren
```

Tokens haben keine Ablaufzeit (Mobile-Standard). Widerruf erfolgt explizit per `DELETE`.

**Fehler-Konventionen:**
- Fehler-Body: `{ "error": "<machine_readable_code>" }`
- Validierungsfehler (422): `{ "errors": [ "Fehlertext 1", "Fehlertext 2" ] }` (Texte sind menschenlesbar, deutsch)
- HTTP-Status entspricht der Fehlerklasse (400 = falscher Input, 401 = Auth fehlt/ungültig, 403 = berechtigt-aber-verboten, 404 = nicht gefunden, 422 = Geschäftsregel-Verletzung, 429 = Rate-Limit, 500 = Server-Bug)

---

## Authentifizierung

### `POST /api/tokens` — Token ausstellen (Login)

Liefert einen Bearer-Token im Klartext. Der Klartext-Token wird **nur einmal** zurückgegeben (in der Antwort dieses Aufrufs) — danach existiert serverseitig nur noch der SHA-256-Hash.

**Request-Body:**

| Feld | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `username` | string | ja | Anmeldename |
| `password` | string | ja | Passwort im Klartext |
| `client` | string | nein | Mandantenname (z. B. `"ASW"`); Default = Mandant `1` |
| `device_name` | string | nein | Beschriftung zur späteren Identifikation (z. B. `"iPhone von Oliver"`), max. 100 Zeichen |

**Beispiel-Request:**
```json
{
  "username": "oliver",
  "password": "geheim",
  "client": "ASW",
  "device_name": "iPhone von Oliver"
}
```

**Beispiel-Response (200):**
```json
{
  "token": "flyres_xJ9kQm8N2pL5vR7tY3wF6cH1aB4dE8gK0mP9sU2nT5rW",
  "user": {
    "id": 42,
    "username": "oliver",
    "firstname": "Oliver",
    "lastname": "Scharfenberger",
    "email": "oliver@…",
    "clientid": 1,
    "roles": ["ROLE_PILOT"],
    "isInstructor": false,
    "isAdmin": false
  }
}
```

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 400 | `missing_credentials` | Username oder Passwort leer |
| 401 | `invalid_credentials` | Falsche Zugangsdaten |
| 403 | `account_locked` | User gelöscht oder gesperrt |
| 403 | `account_not_allowed` | Andere Sicherheits-Vorprüfung fehlgeschlagen |
| 429 | `rate_limited` | Mehr als 5 Versuche pro Minute von dieser IP |

**Rate-Limit:** 5 Versuche pro Minute pro IP-Adresse, unabhängig vom übergebenen Username.

---

### `GET /api/me` — Aktuell angemeldeter User

Verifiziert das Token und liefert die User-Daten. Sinnvoll beim App-Start, um zu prüfen ob ein gespeichertes Token noch gültig ist.

**Auth:** erforderlich.

**Beispiel-Response (200):**
```json
{
  "id": 42,
  "username": "oliver",
  "firstname": "Oliver",
  "lastname": "Scharfenberger",
  "email": "oliver@…",
  "clientid": 1,
  "roles": ["ROLE_PILOT"],
  "isInstructor": false,
  "isAdmin": false
}
```

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 401 | `not_authenticated` | Kein gültiges Token / keine Session |

---

### `DELETE /api/tokens/current` — Logout (App)

Widerruft das aktuell verwendete Bearer-Token. Ab sofort liefern alle Requests mit diesem Token `401`.

**Auth:** erforderlich.

**Response:** `204 No Content` bei Erfolg.

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 400 | `no_token_in_request` | Request war Cookie-basiert (kein Bearer) |
| 401 | `not_authenticated` | Kein gültiger Auth |

---

### `GET /api/tokens` — Eigene aktive Tokens auflisten

Für eine „Andere Geräte abmelden"-Ansicht in der App.

**Auth:** erforderlich.

**Beispiel-Response (200):**
```json
[
  {
    "id": 7,
    "device_name": "iPhone von Oliver",
    "created_at": "2026-06-20T14:32:00+00:00",
    "last_used_at": "2026-06-26T09:15:00+00:00",
    "last_ip": "84.12.34.56",
    "is_current": true
  },
  {
    "id": 8,
    "device_name": "iPad Cockpit",
    "created_at": "2026-06-22T08:00:00+00:00",
    "last_used_at": "2026-06-25T18:01:00+00:00",
    "last_ip": "192.168.1.42",
    "is_current": false
  }
]
```

`is_current: true` markiert das Token, mit dem dieser Request gerade authentifiziert wurde. Reihenfolge: zuletzt genutzte zuerst.

---

### `DELETE /api/tokens/{id}` — Einzelnes Token widerrufen

Widerruft das Token mit der angegebenen ID, sofern es dem authentifizierten User gehört.

**Auth:** erforderlich.

**Path-Parameter:**

| Param | Typ | Beschreibung |
|---|---|---|
| `id` | integer | ID aus `GET /api/tokens` |

**Response:** `204 No Content` bei Erfolg.

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 404 | `not_found` | Token existiert nicht oder gehört einem anderen User |

---

## Stammdaten

Alle Stammdaten-Endpunkte filtern automatisch nach dem Mandanten des angemeldeten Users. Keine Parameter erforderlich. Antworten sind immer Arrays.

### `GET /api/aircraft` — Flugzeuge des Mandanten

```json
[
  { "id": 7,  "type": "C42",      "callsign": "D-MABC" },
  { "id": 12, "type": "Cessna 152", "callsign": "D-EFGH" }
]
```

### `GET /api/instructors` — Fluglehrer

```json
[
  { "id": 12, "name": "Klein, Martin" },
  { "id": 18, "name": "Weber, Anke" }
]
```

(Name-Format: `"Nachname, Vorname"`.)

### `GET /api/pilots` — Piloten / Mandanten-User

```json
[
  { "id": 3,  "name": "Schmidt, Anna" },
  { "id": 42, "name": "Scharfenberger, Oliver" }
]
```

### `GET /api/flightpurposes` — Flugzwecke

```json
[
  { "id": 1, "name": "Privatflug",  "isTraining": false },
  { "id": 2, "name": "Schulung",    "isTraining": true  },
  { "id": 3, "name": "Überprüfung", "isTraining": true  }
]
```

`isTraining: true` markiert Zwecke, bei denen ein Fluglehrer Pflicht ist.

### `GET /api/airfields` — Flugplätze

```json
[
  { "id": 1, "name": "EDFV Worms" },
  { "id": 2, "name": "EDFM Mannheim" }
]
```

---

## Reservierungen

### `GET /api/bookings` — Reservierungs-Liste

**Query-Parameter** (alle optional):

| Param | Werte | Beschreibung |
|---|---|---|
| `view` | siehe Tabelle unten | Welche Auswahl angezeigt wird (Default: `all`) |
| `aircraft` | integer | Nur Reservierungen mit diesem Flugzeug (ID aus `/api/aircraft`) |
| `fi` | integer | Nur Reservierungen mit diesem Fluglehrer (ID aus `/api/instructors`) |
| `pilot` | integer | Nur Reservierungen für diesen Piloten (ID aus `/api/pilots`) |

**Erlaubte `view`-Werte:**

| `view` | Bedeutung |
|---|---|
| `mine` | Eigene + als Fluglehrer zugewiesene (kommende) |
| `mine_history` | Eigene + als Fluglehrer zugewiesene (alle inkl. vergangene) |
| `today` | Heute |
| `tomorrow` | Morgen |
| `thisweek` | Diese Woche |
| `weekafter` | Übernächste Woche |
| `thisweekend` | Dieses Wochenende |
| `nextweekend` | Nächstes Wochenende |
| `all` | Alle (Default) |

**Beispiel-Response (200):**
```json
[
  {
    "id":          4123,
    "date":        "2026-06-25",
    "start":       "10:00",
    "end":         "12:00",
    "aircraft":    "C42 D-MABC",
    "pilotId":     3,
    "pilot":       "Anna Schmidt",
    "instructor":  "Martin Klein",
    "purpose":     "Schulung",
    "isTraining":  true,
    "description": "Platzrunden"
  }
]
```

`instructor` ist `null`, wenn kein Fluglehrer eingetragen ist.

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 400 | `invalid_view` | Unbekannter `view`-Wert; Antwort enthält `allowed`-Array mit erlaubten Werten |

---

### `GET /api/bookings/{id}` — Reservierungs-Detail

**Path-Parameter:**

| Param | Typ | Beschreibung |
|---|---|---|
| `id` | integer | Reservierungs-ID |

**Beispiel-Response (200):**
```json
{
  "id":              4123,
  "aircraft":        "C42 D-MABC",
  "instructor":      "Martin Klein",
  "airfield":        "EDFV Worms",
  "purpose":         "Schulung",
  "start":           "2026-06-25 10:00",
  "end":             "2026-06-25 12:00",
  "reservedFor":     "Schmidt, Anna",
  "reservedAt":      "2026-06-20 14:32",
  "changedBy":       null,
  "changedAt":       null,
  "phone": {
    "home":   "06241-1234",
    "office": null,
    "mobile": "0151-1234567"
  },
  "email":           "anna@…",
  "description":     "Platzrunden",
  "emailInfoIntern": "fi-klein@…",
  "emailInfoExtern": "papa.schmidt@…",
  "canEdit":         true,
  "edit": {
    "aircraftId":       7,
    "flightinstructor": 12,
    "flightpurposeId":  2,
    "airfieldId":       1,
    "date":             "2026-06-25",
    "startTime":        "10:00",
    "endTime":          "12:00",
    "description":      "Platzrunden"
  }
}
```

- `canEdit` zeigt, ob der aktuelle User diese Reservierung ändern/löschen darf.
- `edit` enthält die Rohwerte zum Vorbefüllen eines Bearbeiten-Formulars (IDs statt Namen, getrennte Datums-/Zeitfelder).

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 404 | `not_found` | Reservierung existiert nicht oder gehört nicht zum eigenen Mandanten |

---

### `GET /api/availability` — Freie Zeitfenster

Liefert für einen Tag die freien Slots eines bestimmten Flugzeugs und/oder Fluglehrers. Wenn beides übergeben wird, ist die Schnittmenge in `freeSlots`.

**Query-Parameter:**

| Param | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `date` | string (`YYYY-MM-DD`) | ja | Zieldatum |
| `aircraft` | integer | nein | Flugzeug-ID; ohne Parameter wird kein Flugzeug-Filter angewandt |
| `fi` | integer | nein | Fluglehrer-ID; ohne Parameter kein FI-Filter |

**Beispiel-Request:** `GET /api/availability?date=2026-06-25&aircraft=7&fi=12`

**Beispiel-Response (200):**
```json
{
  "date":           "2026-06-25",
  "dayStart":       "06:30",
  "dayEnd":         "21:45",
  "aircraftId":     7,
  "aircraftFree":   [ { "start": "06:30", "end": "10:00" }, { "start": "12:00", "end": "21:45" } ],
  "instructorId":   12,
  "instructorFree": [ { "start": "08:00", "end": "18:00" } ],
  "freeSlots":      [ { "start": "08:00", "end": "10:00", "minutes": 120 }, { "start": "12:00", "end": "18:00", "minutes": 360 } ]
}
```

- `dayStart`/`dayEnd` sind sonnenstandsbasiert (lokaler Sonnenaufgang/Sonnenuntergang).
- `aircraftFree` ist `null`, wenn kein `aircraft`-Parameter übergeben wurde. Analog für `instructorFree`.
- `freeSlots` ist die Schnittmenge aus Tageszeitfenster, `aircraftFree` und `instructorFree` (wo gesetzt), inkl. `minutes` für die Dauer in Minuten.

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 400 | `invalid_date` | `date` fehlt oder hat nicht das Format `YYYY-MM-DD`; Antwort enthält `expected`-Feld |

---

### `POST /api/bookings` — Neue Reservierung

**Request-Body:**

| Feld | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `aircraftId` | integer | ja | Flugzeug-ID |
| `start` | string | ja | Startzeit, akzeptiert `"YYYY-MM-DD HH:MM"` oder `"YYYY-MM-DDTHH:MM"` (optional mit Sekunden) |
| `end` | string | ja | Endzeit, gleiches Format wie `start` |
| `flightpurposeId` | integer | nein | Flugzweck-ID; bei Schulungen Pflicht |
| `flightinstructor` | integer | nein | Fluglehrer-ID; bei Schulungen Pflicht |
| `airfieldId` | integer\|null | nein | Flugplatz-ID |
| `description` | string | nein | Freitext-Beschreibung |
| `emailInternUserIds` | integer[] | nein | User-IDs, die per Mail benachrichtigt werden sollen |
| `emailInfoExtern` | string | nein | Komma-/Semikolon-separierte externe Mailadressen |
| `createdForUserId` | integer | nein | **Nur für Admins**: Reservierung für einen anderen User anlegen |

**Beispiel-Request:**
```json
{
  "aircraftId":         7,
  "start":              "2026-06-25 10:00",
  "end":                "2026-06-25 12:00",
  "flightpurposeId":    2,
  "flightinstructor":   12,
  "airfieldId":         1,
  "description":        "Platzrunden",
  "emailInternUserIds": [18],
  "emailInfoExtern":    "papa@…"
}
```

**Response (201):** Identisches Shape wie `GET /api/bookings/{id}` (ohne `edit`-Block — der ist nur in der Detail-Antwort).

Beim erfolgreichen Anlegen werden Benachrichtigungs-Mails an Pilot, FI und ggf. zusätzliche Empfänger versendet.

**Fehler:**

| Status | Antwort | Bedeutung |
|---|---|---|
| 400 | `{ "error": "invalid_json" }` | Body ist kein gültiges JSON |
| 401 | `{ "error": "not_authenticated" }` | |
| 403 | `{ "error": "forbidden" }` \| `{ "error": "cross_origin_denied" }` \| `{ "error": "origin_required" }` | Bei Cookie-basierten Calls wird der Origin-Header geprüft. Bearer-Calls sind davon ausgenommen. |
| 422 | `{ "errors": ["...", "..."] }` | Validierungsfehler — Texte sind deutsch und an den Endnutzer adressiert |

**Typische 422-Fehler:**
- „Das Startdatum ist kein gültiges Datum"
- „Das Ende der Reservierung muss später als der Start sein"
- „Für Schulflüge muss ein Fluglehrer ausgewählt werden"
- „Es muss ein Flugzeug ausgewählt werden"
- „Das Flugzeug ist in diesem Zeitfenster bereits reserviert"
- „Der Fluglehrer ist in diesem Zeitfenster nicht verfügbar"

---

### `PATCH /api/bookings/{id}` — Reservierung ändern

Akzeptiert dieselben Felder wie `POST /api/bookings`. Felder, die im Body nicht enthalten sind, bleiben unverändert.

**Path-Parameter:** `id` (Reservierungs-ID).

**Response (200):** Identisches Shape wie `GET /api/bookings/{id}`.

Beim Ändern wird eine Änderungs-Mail mit Vorher/Nachher-Vergleich versendet.

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 400 | `invalid_json` | Body ist kein JSON |
| 403 | `forbidden` | User darf diese Reservierung nicht ändern |
| 404 | `not_found` | Reservierung existiert nicht |
| 422 | siehe `POST /api/bookings` | Gleiche Validierungskette |

---

### `DELETE /api/bookings/{id}` — Reservierung stornieren

**Path-Parameter:** `id`.

**Response (200):**
```json
{ "success": true }
```

Vor dem Löschen wird eine Stornierungs-Mail mit den noch vollständigen Daten versendet.

**Fehler:**

| Status | `error` | Bedeutung |
|---|---|---|
| 403 | `forbidden` | User darf diese Reservierung nicht stornieren |
| 404 | `not_found` | Reservierung existiert nicht |

---

## Wiederkehrende Datentypen

### `User` (in `/api/me` und `/api/tokens` POST-Response)

| Feld | Typ | Bemerkung |
|---|---|---|
| `id` | integer | |
| `username` | string | |
| `firstname` | string | |
| `lastname` | string | |
| `email` | string | |
| `clientid` | integer | Mandanten-ID |
| `roles` | string[] | z. B. `["ROLE_PILOT", "ROLE_FI"]` |
| `isInstructor` | boolean | Convenience: `roles` enthält `ROLE_FI` |
| `isAdmin` | boolean | Convenience: `roles` enthält `ROLE_ADMIN` |

### `BookingListItem` (in `GET /api/bookings`)

| Feld | Typ | Bemerkung |
|---|---|---|
| `id` | integer | |
| `date` | string | `YYYY-MM-DD` |
| `start` | string | `HH:MM` |
| `end` | string | `HH:MM` |
| `aircraft` | string | Anzeigename, z. B. `"C42 D-MABC"` |
| `pilotId` | integer | |
| `pilot` | string | Anzeigename |
| `instructor` | string\|null | Anzeigename oder `null` |
| `purpose` | string | Klartext-Bezeichnung |
| `isTraining` | boolean | |
| `description` | string\|null | |

### `Slot` (in `/api/availability`)

| Feld | Typ | Bemerkung |
|---|---|---|
| `start` | string | `HH:MM` |
| `end` | string | `HH:MM` |
| `minutes` | integer | nur bei `freeSlots`, sonst nicht vorhanden |

### `TokenInfo` (in `GET /api/tokens`)

| Feld | Typ | Bemerkung |
|---|---|---|
| `id` | integer | |
| `device_name` | string\|null | Beim Login gesetzter Label |
| `created_at` | string | ISO 8601 |
| `last_used_at` | string\|null | ISO 8601 oder `null` (noch nie genutzt) |
| `last_ip` | string\|null | IPv4/IPv6 als String |
| `is_current` | boolean | Markiert das Token dieses Requests |

---

## Auth-Workflow im Überblick

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│   App-Start                                                 │
│      │                                                      │
│      ▼                                                      │
│   Token in Secure-Storage?                                  │
│      │                                                      │
│      ├─ Nein  ──►  Login-Screen                             │
│      │              │                                       │
│      │              ▼                                       │
│      │           POST /api/tokens                           │
│      │              │                                       │
│      │              ├─ 200  ──►  Token speichern  ──┐       │
│      │              ├─ 401          (Fehler zeigen) │       │
│      │              └─ 429          (warten)        │       │
│      │                                              │       │
│      └─ Ja                                          │       │
│             │                                       │       │
│             ▼                                       │       │
│         GET /api/me  mit Bearer ◄───────────────────┘       │
│             │                                               │
│             ├─ 200  ──►  Main-Screen                        │
│             └─ 401  ──►  Token verwerfen, Login-Screen      │
│                                                             │
│                                                             │
│   Logout-Knopf                                              │
│      │                                                      │
│      ▼                                                      │
│   DELETE /api/tokens/current (best-effort)                  │
│      ▼                                                      │
│   Lokales Token aus Secure-Storage löschen                  │
│      ▼                                                      │
│   Zurück zum Login-Screen                                   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

**Best Practices für Clients:**

- Bei jedem `401 invalid_token` → Token lokal löschen und Login-Screen zeigen. Niemals Token mehrfach wiederverwenden.
- Token immer im Secure-Storage / Keychain halten, nie in Plain-Text-Logs schreiben.
- Beim `429 rate_limited` mindestens 60 Sekunden warten, bevor erneut versucht wird.
- `GET /api/me` einmal beim App-Start als Health-Check für das gespeicherte Token nutzen, NICHT vor jedem anderen Call.
- Bei Schreib-Endpunkten (`POST`, `PATCH`, `DELETE`) Status `422` als „Validierungsfehler, dem User zeigen" behandeln — die Texte sind direkt anzeigbar.
- `403 cross_origin_denied` / `origin_required` betreffen nur Cookie-basierte Browser-Clients und treten bei Bearer-Auth nicht auf.

---

## Hinweise zu Datums-/Zeitformaten

| Wo | Format |
|---|---|
| Listenantworten: `date` + `start`/`end` | `"YYYY-MM-DD"` und `"HH:MM"` getrennt |
| Detail-Antwort: `start`/`end` | `"YYYY-MM-DD HH:MM"` zusammen (Anzeige-Format) |
| Detail-Antwort: `edit.date` + `edit.startTime` / `edit.endTime` | `"YYYY-MM-DD"` und `"HH:MM"` getrennt (Formular-Format) |
| Request-Body: `start`/`end` | `"YYYY-MM-DD HH:MM"` oder `"YYYY-MM-DDTHH:MM"` — beide akzeptiert |
| Audit-Felder (`created_at`, `last_used_at`) | ISO 8601 mit Timezone |

Sämtliche Zeiten sind lokale Zeit (Europe/Berlin), nicht UTC.
