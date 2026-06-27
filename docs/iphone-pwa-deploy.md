# FlyRes – Deploy auf Subdomain `flyres.flugschule-worms.de`

Zielstruktur (alles **ein** Origin → Session-Anmeldung der PWA funktioniert):

```
https://flyres.flugschule-worms.de/            ← klassische App (weeksview …)
https://flyres.flugschule-worms.de/api/…       ← JSON-API
https://flyres.flugschule-worms.de/mobile/     ← iPhone-/Android-PWA
```

> **Begriffe:** `<projekt>` = Symfony-Ordner auf dem Server · `<public>` = dessen
> `public/`-Verzeichnis (= Document Root der Subdomain).

---

## 0. Subdomain einrichten (einmalig, Plesk)

1. In Plesk **Subdomain** `flyres` zu `flugschule-worms.de` anlegen.
2. **Document Root** der Subdomain auf das **`public/`-Verzeichnis** der Symfony-
   Installation zeigen lassen (z.B. `…/symfony64/public`).
3. **HTTPS** aktivieren (Let's Encrypt) – Pflicht für die PWA.
4. Test: `https://flyres.flugschule-worms.de/login` muss die FlyRes-Login-Seite zeigen.

> Dadurch fällt der hässliche Pfad `…/symfony64/public/` weg – die App liegt sauber
> unter der Subdomain-Wurzel.

---

## 1. Backend-Dateien hochladen (PHP)

In den Projekt-Ordner `<projekt>` (gleiche relativen Pfade wie lokal):

| Lokal | Server-Ziel |
|---|---|
| `src/Controller/Api/` *(5 Dateien)* | `<projekt>/src/Controller/Api/` |
| `src/Entities/Bookings.php` *(geändert)* | `<projekt>/src/Entities/Bookings.php` |
| `config/routes/api.yaml` | `<projekt>/config/routes/api.yaml` |
| `config/packages/security.yaml` *(geändert)* | `<projekt>/config/packages/security.yaml` |
| `config/packages/prod/doctrine.yaml` *(geändert – Proxies zur Laufzeit)* | `<projekt>/config/packages/prod/doctrine.yaml` |
| `config/routes.yaml`, `src/Controller/LoginController.php` | nur falls `/api/login` dort noch fehlt |

## 2. Frontend hochladen (PWA)

Kompletten Inhalt von **`public/mobile/`** in `<public>/mobile/`:

⚠️ **`public/mobile/.htaccess` ist versteckt** – im FTP-Programm „versteckte Dateien
anzeigen" aktivieren, sonst fängt Symfony `/mobile/` ab.

## 3. Server-Cache leeren

Ordner **`<projekt>/var/cache/prod/`** löschen (baut sich beim nächsten Aufruf neu auf).
Dank `auto_generate_proxy_classes: true` ist das ohne Konsole gefahrlos.

## 4. Prüfen

1. Eingeloggt: `https://flyres.flugschule-worms.de/api/me` → muss **JSON** liefern
   (404 = Routen nicht geladen / Cache nicht geleert).
2. `https://flyres.flugschule-worms.de/mobile/` → die App lädt; anmelden, Listen prüfen.

## 5. Auf dem Handy installieren

- **iPhone:** Safari → `https://flyres.flugschule-worms.de/mobile/` → Teilen → „Zum Home-Bildschirm".
- **Android:** Chrome → gleiche URL → „App installieren".

---

## Wichtig: Joomla-Login-Brücke (klassische Einbettung)

Bisher lief FlyRes als iframe **unter `www.flugschule-worms.de`** (gleiche Domain wie
Joomla). Auf der neuen Subdomain ist die App **cross-origin** zur Joomla-`www`-Seite.

- Die **PWA** ist davon nicht betroffen (sie ist same-origin mit der API auf `flyres.…`).
- Die **klassische Joomla-Einbettung** (iframe + Login-Modul, das `/api/login` aufruft)
  muss ggf. angepasst werden: entweder die App direkt unter der Subdomain öffnen
  (statt iframe in www), oder CORS für `/api/login|logout|verify` auf der Subdomain
  freigeben. → Bei Bedarf richte ich das ein.

## Hinweise
- **Pfad-unabhängiger Build:** relative Asset-Pfade + aus der URL berechnete API-Basis
  (`…/mobile/` → `…/api/`). Kein Anpassen nötig, falls der Pfad mal wechselt.
- **Updates später:** `cd frontend && npm run build` → `public/mobile/` neu hochladen.
- App-Icons liegen in `frontend/public/icons/` (Generator: `frontend/scripts/make-icons.php`).
