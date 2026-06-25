# FlyRes iPhone-PWA (Frontend)

Vue 3 + Vite PWA. Spricht die JSON-API des bestehenden Symfony (`/api/*`) über
das Session-Cookie (gleiche Domain). Wird vom Symfony-Server unter `/app/`
ausgeliefert.

## Voraussetzungen
- Node.js 18+ (nur zum Bauen, nicht auf dem Server nötig)

## Entwicklung (mit Dev-Proxy)
Zwei Server gleichzeitig: Symfony (Backend) + Vite (Frontend mit Hot-Reload).
Der Vite-Dev-Server leitet `/api/*` automatisch an Symfony weiter (siehe
`vite.config.js` → `server.proxy`).

**1. Symfony starten** (Projekt-Wurzel), Standard-Ziel ist `127.0.0.1:8000`:
```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

**2. Vite starten** (in `frontend/`):
```bash
npm install
npm run dev
```

**3. Im Browser öffnen:** http://localhost:5173/app/

Anmelden mit echten FlyRes-Zugangsdaten – die Session läuft über den Proxy.
Änderungen am Vue-Code sind sofort sichtbar (Hot-Reload).

Läuft dein Symfony woanders, das Ziel überschreiben:
```bash
VITE_API_TARGET=http://127.0.0.1:8080 npm run dev
```
Hinweis: Der Proxy lässt den Host bewusst unverändert (`changeOrigin:false`),
damit der CSRF-Origin-Check im Backend auch im Dev-Modus greift. Verwende daher
ein Backend, das unter `/api/...` direkt erreichbar ist (z.B. das `php -S` oben),
keine Sub-Pfad-Installation.

## Build & Veröffentlichen
```bash
cd frontend
npm install
npm run build
```
Das schreibt die fertige App direkt nach `../public/app/` (siehe
`vite.config.js` → `build.outDir`). Diese Dateien sind Teil des normalen
FlyRes-Deployments – einfach mit ausliefern.

Danach im Browser `https://<deine-domain>/app/` öffnen.

## Auf dem iPhone installieren
Safari → Seite `…/app/` öffnen → Teilen-Symbol → **„Zum Home-Bildschirm"**.
Die App startet dann im Vollbild mit eigenem Icon.

## Voraussetzungen serverseitig
- HTTPS (Pflicht für Service Worker / PWA)
- Der Webserver liefert `/app/index.html` für die URL `/app/` aus
  (Standard-DirectoryIndex genügt; es wird kein History-Routing verwendet)

## Icons
Siehe `public/icons/README.txt` – ein quadratisches Logo in 192/512 px ablegen.

## Struktur
```
src/
  api.js            fetch-Wrapper gegen /api/* (credentials: 'include')
  App.vue           Auth-Status, Tab-Navigation, Detail-Overlay
  views/            LoginView, MyFlightsView, AllView, ReserveView
  components/       TabBar, BookingCard, FilterSheet, DetailSheet
  util.js, style.css
```
