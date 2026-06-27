# Bearer-Token: Manueller Smoke-Test

Voraussetzungen:
- `sql/2026-06-26-api-tokens.sql` wurde gegen die Ziel-DB ausgeführt
- FlyRes läuft lokal (z. B. `symfony serve` oder Apache)
- Gültige Zugangsdaten (Username + Passwort + Client-Name) liegen vor

## 1. Token anfordern (Login)

```bash
curl -i -X POST http://localhost:8000/api/tokens \
  -H 'Content-Type: application/json' \
  -d '{"username":"<USER>","password":"<PASS>","client":"ASW","device_name":"Test-Curl"}'
```

Erwartet: `200 OK`, JSON mit `token` (Format `flyres_…`) und `user`-Objekt.
Token kopieren — er ist nur **einmal** sichtbar.

## 2. Authentifizierter Request (Daten lesen)

```bash
TOKEN='flyres_xxx…'
curl -i http://localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
```

Erwartet: `200 OK`, User-JSON (selber Inhalt wie aus Schritt 1).

## 3. Falsches Token

```bash
curl -i http://localhost:8000/api/me -H 'Authorization: Bearer flyres_invalid'
```

Erwartet: `401 Unauthorized`, `{"error":"invalid_token"}`.

## 4. Eigene Tokens auflisten

```bash
curl -i http://localhost:8000/api/tokens -H "Authorization: Bearer $TOKEN"
```

Erwartet: `200`, Array mit mindestens einem Eintrag, `is_current: true`.

## 5. Aktuelles Token widerrufen (Logout)

```bash
curl -i -X DELETE http://localhost:8000/api/tokens/current -H "Authorization: Bearer $TOKEN"
```

Erwartet: `204 No Content`.

## 6. Nach Widerruf nicht mehr nutzbar

```bash
curl -i http://localhost:8000/api/me -H "Authorization: Bearer $TOKEN"
```

Erwartet: `401`.

## 7. Rate-Limit

```bash
for i in 1 2 3 4 5 6; do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/api/tokens \
    -H 'Content-Type: application/json' \
    -d '{"username":"falsch","password":"falsch","client":"ASW"}'
done
```

Erwartet: 5x `401` (invalid_credentials), dann `429` (rate_limited).

## 8. PWA bleibt funktional (Regressionstest)

```bash
# Cookie-Login wie bisher
curl -i -c cookies.txt -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"<USER>","password":"<PASS>","client":"ASW"}'

# Authentifizierter Cookie-Request
curl -i -b cookies.txt http://localhost:8000/api/me
```

Erwartet: beide `200`. Cookie-Auth wurde durch Bearer-Authenticator **nicht** gebrochen.

## 9. Cross-Origin-Check übersprungen bei Bearer

POST mit Bearer, **ohne** Origin-Header:

```bash
curl -i -X POST http://localhost:8000/api/bookings \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{...gueltiges-booking...}'
```

Erwartet: kein `origin_required`-Fehler (Bearer-Marker setzt denyCrossOrigin außer Kraft). Antwort hängt vom Booking-Payload ab, aber **nicht** `403 origin_required`.
