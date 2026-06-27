# FlyRes – Use-Case-Katalog

Extrahiert aus dem gesamten Programm: klassische Web-App (Symfony/Twig) **und** iPhone-PWA (Vue) mit JSON-API.
Akteure (Rollen, aufsteigend, jede erbt die darunter): **Gast/öffentlich → Pilot → Fluglehrer (FI) → Admin → System-Admin → Global-Admin** sowie **System** (automatische Abläufe).
Kanal: **W** = klassische Web-App, **P** = PWA/Mobile, **API** = JSON-Schnittstelle.

---

## A. Authentifizierung & Zugang
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| A1 | Anmelden mit Mandantenauswahl + Benutzer/Passwort | Gast | W |
| A2 | Anmelden über die Joomla-Brücke (iframe, `loginwithcredentials`) | Gast | W |
| A3 | Abmelden | angemeldet | W/P |
| A4 | PWA-Login (JSON, Session-Cookie) | Gast | API |
| A5 | Session prüfen / eigenes Profil laden (`/api/verify`, `/api/me`) | angemeldet | API |
| A6 | Aktive Mandantenliste fürs Login abrufen (`/api/clients`) | öffentlich | API |
| A7 | Sonnenauf-/untergangszeiten abrufen (`/sunrise_sunset`) | öffentlich | W |

## B. Buchungen ansehen
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| B1 | Monatsübersicht aller Flugzeuge (Ampel-Auslastung) | Pilot | W |
| B2 | 14-Tage-/Wochenansicht | Pilot | W |
| B3 | Tagesansicht | Pilot | W |
| B4 | Generalview mit Filtern: eigene, eigene Historie, alle, heute, morgen, diese/nächste Woche, dieses/nächstes Wochenende, nach Fluglehrer, nach Flugzeug, nach Nutzer, nur Schulung | Pilot | W |
| B5 | Buchungsdetails ansehen | Pilot | W/P |

## C. Reservieren & Buchungen verwalten
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| C1 | Neue Reservierung anlegen (Flugzeug, Zeit, Zweck, Fluglehrer, Flugplatz) | Pilot | W/P |
| C2 | Freie Slots/Verfügbarkeit eines Tages ermitteln | Pilot | W/P/API |
| C3 | Verfügbare Fluglehrer zur Buchung anzeigen (Ajax) | Pilot | W |
| C4 | Eigene Reservierung bearbeiten | Pilot | W/P |
| C5 | Reservierung speichern (Validierung + Mailversand) | Pilot | W/P |
| C6 | Für anderen Nutzer buchen / Vorlauf- & Buchungsregeln übergehen | Admin | W |
| C7 | Reservierung stornieren/löschen (Soft-Delete + Stornomail) | Pilot (eigene) / Admin | W/P |
| C8 | Mehrtägige Buchungen korrekt anlegen/anzeigen | Pilot | W/P |

## D. Fluglehrer-Verfügbarkeit
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| D1 | Verfügbarkeitsraster der Fluglehrer ansehen | Pilot | W |
| D2 | Eigene Verfügbarkeit neu eingeben | Fluglehrer | W |
| D3 | Eigene Verfügbarkeiten bearbeiten/löschen | Fluglehrer | W |
| D4 | Verfügbarkeiten aller Fluglehrer eingeben/bearbeiten | System-Admin | W |

## E. Nutzer & Stammdaten-Personen
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| E1 | Eigene Stammdaten verwalten (Profil, Mail-Benachrichtigungs-Optionen) | Pilot | W |
| E2 | Nutzerliste verwalten | Admin | W |
| E3 | Neuen Nutzer anlegen | Admin | W |
| E4 | Fremden Nutzer bearbeiten | Admin | W |
| E5 | Nutzer löschen (Soft-Delete + Kaskade auf Buchungen) | Admin | W |
| E6 | Rollen/Funktionen eines Nutzers vergeben (bis System-Admin) | System-Admin | W |
| E7 | Globale Rolle „Global-Administrator" vergeben | Global-Admin | W |
| E8 | Mailverteiler (Outlook/Apple) aller Nutzer exportieren | System-Admin | W |

## F. Flugzeuge & Flugzeugtypen
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| F1 | Flugzeugliste verwalten | Admin | W |
| F2 | Flugzeug anlegen / bearbeiten (inkl. Vorlaufzeit) | Admin | W |
| F3 | Flugzeug löschen (Soft-Delete + Kaskade auf Buchungen) | Admin | W |
| F4 | Flugzeugtypen verwalten | Admin | W |
| F5 | Flugzeugtyp anlegen/bearbeiten (inkl. benötigter Lizenztypen) | Admin | W |
| F6 | Flugzeugtyp löschen (Soft-Delete, rückgängig machbar) | Admin | W |

## G. Lizenzen & Lizenztypen
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| G1 | Eigene Lizenzen ansehen/verwalten | Pilot | W |
| G2 | Eigene Lizenz anlegen | Pilot | W |
| G3 | Lizenzen anlegen/bearbeiten/löschen (Soft-Delete + Mail) | Pilot (eigene) | W |
| G4 | Lizenzen eines anderen Nutzers bearbeiten | Admin | W |
| G5 | Lizenzen aller Nutzer auflisten | Admin | W |
| G6 | Abgelaufene/bald ablaufende Lizenzen anzeigen | Admin | W |
| G7 | Lizenztypen verwalten (anlegen/bearbeiten/löschen) | Global-Admin | W |

## H. Pinnwand / Notizen
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| H1 | Pinnwandeintrag anlegen | Pilot | W |
| H2 | Eigene Einträge ansehen | Pilot | W |
| H3 | Eigenen Eintrag bearbeiten/löschen (Soft-Delete) | Pilot | W |
| H4 | Alle Einträge ansehen/verwalten | System-Admin | W |
| H5 | Gültigkeit > 1 Monat setzen | System-Admin | W |

## I. Konfiguration / Mandanten
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| I1 | Mandantenliste ansehen | Global-Admin | W |
| I2 | Neuen Mandanten anlegen | Global-Admin | W |
| I3 | Mandant umbenennen/bearbeiten | Global-Admin | W |
| I4 | Mandant aktiv/inaktiv schalten (deaktivieren → aus Login ausgeblendet) | Global-Admin | W |

## J. iPhone-PWA (Mobile)
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| J1 | Mobil anmelden (Mandant + Benutzer/Passwort) | Gast | P |
| J2 | „Meine Flüge" ansehen | Pilot | P |
| J3 | „Alle" Buchungen ansehen + filtern (Zeitraum/Flugzeug/Fluglehrer/Pilot) | Pilot | P |
| J4 | Buchungsdetail im Sheet ansehen | Pilot | P |
| J5 | Reservieren mit automatischer Vorwahl freier Blöcke | Pilot | P |
| J6 | Eigene Reservierung bearbeiten | Pilot | P |
| J7 | Reservierung löschen | Pilot | P |
| J8 | Flotten-Monatsübersicht (Auslastung als Heatmap) | Pilot | P |
| J9 | Tagesdetail je Flugzeug ansehen + von dort reservieren | Pilot | P |
| J10 | App „zum Homescreen hinzufügen" / Offline-Shell (PWA) | Pilot | P |

## K. Systemseitige / automatische Abläufe
| ID | Use Case | Akteur | Kanal |
|----|----------|--------|-------|
| K1 | Benachrichtigungsmails bei Buchungsänderung (Admins, Pilot, Fluglehrer, interne/externe Adressen) | System | – |
| K2 | Benachrichtigungsmails bei Lizenzänderung | System | – |
| K3 | Auslastungsfarben aus Sonnenstand (Worms) + Flugplatz-Öffnungszeiten berechnen | System | – |
| K4 | Mandantentrennung der Daten (clientid) durchsetzen | System | – |
| K5 | Fehler-Benachrichtigung per Mail an den Entwickler (Server-Fehler 5xx) | System | – |

---

### Anmerkungen
- **Rollenvergabe** erfolgt über „Funktionen" (`FresFunction` ↔ `FRes_user2Functions`), die zur Laufzeit über die Rollen-Hierarchie expandiert werden.
- Mehrere Use Cases existieren **doppelt** in Web und PWA (z. B. Reservieren, Buchung ansehen/bearbeiten/löschen) – die PWA nutzt dieselbe Fachlogik über die JSON-API.
- „Soft-Delete" heißt durchgängig: Status auf `geloescht` (Buchungen `storniert`), Daten bleiben erhalten.
