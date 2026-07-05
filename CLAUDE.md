# FlyRes – Projekt-Hinweise für Claude

Flugschul-Reservierungssystem, mandantenfähig (Symfony 6.4, PHP 8.4, Doctrine).
Zwei Frontends: klassisch + **modernes Web** (`templates/modern/`, Routen `/web/*`) und
iPhone-**PWA** (Vue 3 in `frontend/src/`, Build nach `public/mobile/`).

## Design-Konventionen (modernes Web) – VERBINDLICH

Alle Seiten unter `templates/modern/` folgen **`docs/modern-design.md`**. Kurzfassung:

- **Kopfzeile:** immer Karteireiter über `_pagehead.html.twig` (`ph.tabs([...])`), Block
  `pagehead` leer überschreiben. Einzel-Seiten = Solo-Reiter (Titel).
- **Aufbau:** `.fr-panel` → `.fr-phead` (Zeile 1: Suche/Filter/Navigation) →
  `.fr-pactions` (Zeile 2: Aktionsbuttons **links**) → `.fr-pbody` (Inhalt).
- **Filter** als Dropdowns (`.fr-fl`), nicht als Chips.
- **Buttons:** einheitlich `.fr-btn` (App-Blau, weiß). Löschen = `.fr-btn.del` (blau + roter
  Rand). Abbrechen direkt neben Speichern. Aktionszeile bei Formularen oben (Submit per
  `form="…"`-Attribut).
- **Icons:** nur Linien-Icons aus `_icons.html.twig` (`icon.ic('…')`), keine Emoji.
- **Ton:** „du", freundlich/unterstützend.

Details, Beispiele und die Begründung stehen in **`docs/modern-design.md`** – bei Design-
Arbeit an modernen Seiten dort nachsehen.

## Arbeitsweise

- Twig-Änderungen mit `php bin/console lint:twig <datei>` prüfen; danach
  `php bin/console cache:clear --env=dev`.
- PHP-Lint: `php -l <datei>`.
- Deploy: Templates/CSS → `var/cache/prod` leeren. **PHP-Klassen → OPcache-Reset**
  (PHP-FPM-Neustart). PWA → `cd frontend && npm run build`, `public/mobile/` hochladen.
- Commits enden mit `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
