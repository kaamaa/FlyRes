# FlyRes iPhone-PWA – Deploy per FTP

Ziel: Die PWA unter **https://www.flugschule-worms.de/symfony64/public/app/**
bereitstellen. Da die App `…/api/*` auf **derselben** Domain aufruft, müssen
**Backend (PHP)** und **Frontend (public/app/)** auf den Server.

> **Pfade auf dem Server** (gespiegelt zur URL `…/symfony64/public/…`):
> `<projekt>` = der Symfony-Ordner `…/symfony64/` · `<public>` = `…/symfony64/public/`.

---

## 1. Backend-Dateien hochladen (PHP)

In den **Projekt-Ordner** `<projekt>` (gleiche relative Pfade wie lokal):

| Lokal (dein Repo) | Server-Ziel |
|---|---|
| `src/Controller/Api/` *(ganzer Ordner, 5 Dateien)* | `<projekt>/src/Controller/Api/` |
| `src/Entities/Bookings.php` *(geändert – „Meine Flüge" zeigt auch FI-Flüge)* | `<projekt>/src/Entities/Bookings.php` |
| `config/routes/api.yaml` | `<projekt>/config/routes/api.yaml` |
| `config/packages/security.yaml` *(geändert – überschreiben)* | `<projekt>/config/packages/security.yaml` |
| `config/packages/prod/doctrine.yaml` *(geändert – Proxies zur Laufzeit, FTP-tauglich)* | `<projekt>/config/packages/prod/doctrine.yaml` |
| `config/routes.yaml` *(enthält `/api/login` – überschreiben)* | `<projekt>/config/routes.yaml` |
| `src/Controller/LoginController.php` *(enthält `apiLogin`)* | `<projekt>/src/Controller/LoginController.php` |

> Die letzten beiden (`routes.yaml`, `LoginController.php`) sind nötig, falls auf
> dem Server noch keine `/api/login`-Route existiert. Wenn die Anmeldung über
> `/api/login` dort schon läuft, kannst du sie weglassen.

## 2. Frontend hochladen (statisch)

Den **kompletten Inhalt** von `public/app/` in den Web-Ordner unter `app/`:

| Lokal | Server-Ziel |
|---|---|
| `public/app/*` *(alle Dateien inkl. `assets/`, `sw.js`, `manifest.webmanifest`)* | `<public>/app/` |

⚠️ **Wichtig:** Die Datei **`public/app/.htaccess` ist versteckt** (beginnt mit Punkt).
Im FTP-Programm „versteckte Dateien anzeigen" aktivieren (FileZilla: Server →
Versteckte Dateien anzeigen erzwingen), sonst wird `/app/` von Symfony abgefangen.

## 3. Server-Cache leeren (WICHTIG)

Symfony lädt neue Routen/Controller erst nach Cache-Leerung. Per FTP:

- Den Ordner **`<projekt>/var/cache/prod/`** löschen (komplett). Beim nächsten
  Seitenaufruf baut Symfony ihn neu auf.

> **Wichtig (war die Ursache des `__CG__...Proxies`-Fehlers):** Auf dem Server
> dürfen die Doctrine-Proxies nicht fehlen. Deshalb steht in
> `config/packages/prod/doctrine.yaml` jetzt `auto_generate_proxy_classes: true`
> – damit erzeugt Doctrine fehlende Proxies **zur Laufzeit** selbst, und das
> Leeren von `var/cache/prod/` ist ohne Konsole/`cache:warmup` **gefahrlos**.
> Diese Datei muss mit hochgeladen sein (siehe Schritt 1).

Falls du doch SSH/Konsole hast, geht alternativ:
```bash
php bin/console cache:clear --env=prod
```

## 4. Prüfen

1. Im Browser eingeloggt in FlyRes:
   `https://www.flugschule-worms.de/symfony64/public/api/me`
   → muss **JSON** liefern (deine Userdaten, oder `{"error":"not_authenticated"}`).
   **Ein 404 bedeutet:** Routen nicht geladen → `api.yaml` fehlt oder Cache nicht geleert.
2. `https://www.flugschule-worms.de/symfony64/public/app/`
   → die App lädt. Anmelden, Listen prüfen.

## 5. Auf dem iPhone installieren

Safari → `https://www.flugschule-worms.de/symfony64/public/app/` → Teilen-Symbol →
**„Zum Home-Bildschirm"**. Jetzt echtes App-Icon + Vollbild (PWA).

---

## Voraussetzungen / Hinweise

- **HTTPS** muss aktiv sein (Pflicht für Service Worker / PWA). ✔ bei flugschule-worms.de
- **App-Icons:** In `public/app/icons/` liegen noch keine echten Icons (nur eine
  README). Lege dort `icon-192.png`, `icon-512.png`, `icon-512-maskable.png` ab
  und lade sie mit hoch – sonst ist das Home-Bildschirm-Symbol ein Platzhalter.
- **Pfad-unabhängig:** Der Build nutzt relative Pfade – egal ob `/flyres/app/`,
  `/app/` o.ä., du musst nichts anpassen.
- **Produktions-DB:** Läuft bereits über `.env` auf dem Server (MySQL 8). Tipp:
  in der aktiven `DATABASE_URL` `?serverVersion=8.0.36` ergänzen.
- **Updates später:** Nur `npm run build` neu ausführen und `public/app/` erneut
  hochladen (Backend nur bei API-Änderungen). Der Service Worker aktualisiert die
  App beim nächsten Öffnen automatisch.
