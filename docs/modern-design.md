# Design-Konventionen – modernes Web-Frontend (`templates/modern/`)

Verbindliche Regeln für alle Seiten unter `/web/*` (Twig, Layout `modern/layout.html.twig`).
Neue Seiten und Änderungen **müssen** diesen Konventionen folgen. Das Design steckt
größtenteils schon in wiederverwendbaren Bausteinen – diese nutzen, nicht neu erfinden.

## Bausteine (immer verwenden)

- **Kopfzeile / Reiter:** `templates/modern/_pagehead.html.twig`
  ```twig
  {% import 'modern/_pagehead.html.twig' as ph %}
  {% import 'modern/_icons.html.twig' as icon %}
  {% block pagehead %}{% endblock %}   {# entfernt die alte Titelleiste #}
  {% block content %}
    {{ ph.tabs([
      {label:'Meine', icon:'user', href: path('...'), active: x=='meine'},
      {label:'Alle',  icon:'list', href: path('...'), active: x=='alle'}
    ]) }}
    <div class="fr-panel"> … </div>
  {% endblock %}
  ```
- **Icons:** `templates/modern/_icons.html.twig` (`{{ icon.ic('plus') }}`). Monochrome
  Linien-Icons, stroke 1.9 – identisch zur Sidebar. **Keine Emoji.** Neue Icons dort ergänzen.
- **Sidebar:** `templates/modern/_sidebar.html.twig` (eigenes Icon-Makro `ui.ic`).

## Seitenaufbau (jede Seite gleich)

Jede Seite = **Karteireiter** + **Panel**. Auch Seiten mit nur einer Sektion bekommen
einen **Solo-Reiter** (Objekt ohne `href`) = Seitentitel mit Icon.

```
Karteireiter (ph.tabs)          ← Reiter, kräftige Rahmen; aktiver = weiß
└─ .fr-panel                    ← verbundenes Panel
   ├─ .fr-phead      (Zeile 1)  ← Suche · Filter (Dropdowns) · Navigation
   ├─ .fr-pactions   (Zeile 2)  ← Aktionsbuttons, LINKS, dezent hinterlegt
   └─ .fr-pbody                 ← Inhalt
```
Fehlt eine Zeile (keine Filter / keine Aktion), entfällt sie (`.fr-pactions:empty` blendet aus).

## Kontroll-Leiste (zweizeilig)

- **Zeile 1 `.fr-phead`:** links Suche (`.fr-searchwrap` + `.fr-search`), dann Filter/Felder.
  Einheitlicher Stil (Variante A): **gestapeltes Uppercase-Label über dem Feld** via
  `<label class="fr-fl">Label <select>…</select></label>` (bzw. `<div class="fr-fl">` für
  Navigation/Datum). Alle Felder **38px hoch**, Rahmen `#c9d3e0`, Radius 9px, unten buendig
  (`.fr-phead{align-items:flex-end}`). Keine Filter-Chips – immer Dropdowns. Navigation als
  `.fr-fl` mit `.fr-pernav`, Datum als `.fr-fl` mit `.fr-ctrl.dt`. Werkzeug-Filter
  (`.wb-top`/`.fp-params`/`.srss-form`) folgen demselben Feld-/Label-Stil.
- **Zeile 2 `.fr-pactions`:** alle Aktionsbuttons **linksbündig**. Destruktive Aktion mit
  `<span class="fr-pgap"></span>` abgesetzt.

## Buttons

- **Ein Stil für alle:** `.fr-btn` = solides App-Blau, **immer weiß** (`a.fr-btn{color:#fff}`
  ist nötig, weil `.fr-modern a{color:inherit}` sonst gewinnt → schwarzer Text).
- **Primär/Erstellen:** `.fr-btn` mit `{{ icon.ic('plus') }}` + Text („Neue …").
- **Destruktiv (Löschen):** `.fr-btn.del` = gleiches Blau **+ roter Rand** (inset-Shadow,
  keine Größenänderung).
- **Sekundär/Kontur:** `.fr-btn.sec` nur wo nötig (dunkler Text auf Weiß).
- **Zurück (Detailseiten):** als Kontur-Button `.fr-btn.sec` **links in der Aktionszeile**
  (Ebene 3), mit `.fr-pgap`-Abstand vor den eigentlichen Aktionen. Nicht in den Reiter.
- **Werkzeug-Seiten (W&B, Flugplanung, Sonnenzeiten):** die Eingabe-/Parameterzeile bekommt
  im eingeloggten Modus zusätzlich die Klasse `fr-phead` (= Ebene 2); die Aktion
  (Drucken/Anzeigen) in `.fr-pactions` (Submit ggf. per `form="…"`). Im Embed-Modus unveraendert.
- **Abbrechen:** sitzt **direkt neben Speichern** (kein `margin-left:auto`). Bei Formularen
  liegt die Aktionszeile **oben** im Panel; der obere Submit-Button erreicht das Formular
  per `form="<id>"`-Attribut, damit die Formularbreite unverändert bleibt.

## Panel-Inhalt

- Eigene Karten im Panel neutralisieren, damit keine Doppelrahmen entstehen
  (`.fr-pbody > .fr-daypanel`, `.fr-pbody .fr-ovcard`, `.fr-card` → `.fr-pbody`).
- Client-seitige Umschaltung (z. B. Verfügbarkeit 4 Reiter) bleibt per JS; Reiter/Toggle
  über `.fr-tab`-Buttons mit `data-*`.

## Farben (CSS-Variablen, `modern.css`)

`--fr-blue #1f6fb2` · `--fr-blue-d #155189` · `--fr-ink #1d2733` · `--fr-mut #6b7888`
· `--fr-line #e4e8ee` · `--fr-red #d23b3b`. Reiter-Rahmen `#c2cfe0`.

## Ton / Texte

- Anrede **„du"**, freundlich und unterstützend. Bei „Fluglehrer offen"-Hinweisen immer
  betonen, dass die Flugschule bei der Suche **unterstützt** (Web + Mobile-PWA konsistent).

## Deploy-Hinweise

- Template-/CSS-Änderungen: hochladen + `var/cache/prod` leeren.
- **PHP-Klassenänderungen: OPcache zurücksetzen** (PHP-FPM-Neustart), `var/cache/prod`-Clear reicht nicht.
- Mobile-PWA-Texte (`frontend/src/`): `npm run build` (Ausgabe `public/mobile/`), Ordner hochladen.
