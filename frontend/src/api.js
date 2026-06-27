// Schlanker fetch-Wrapper gegen die Symfony-JSON-API (gleiche Domain -> Session-Cookie).
// Bei 401 wird ein 'unauthorized'-Event ausgeloest, damit App.vue den Login zeigt.

const bus = new EventTarget()
export const onUnauthorized = (cb) => bus.addEventListener('unauthorized', cb)

// API-Basis aus dem aktuellen Pfad ableiten: die PWA liegt unter ".../mobile/",
// die API unter ".../api/". Funktioniert unter jedem Unterordner
// (z.B. flyres.../mobile/ -> flyres.../api/) und im Dev (/mobile/ -> /api/).
const API_BASE = (location.pathname.replace(/\/mobile(\/.*)?$/, '/') || '/') + 'api/'

async function handle(res) {
  const ct = res.headers.get('content-type') || ''
  const body = ct.includes('application/json') ? await res.json() : null
  if (res.status === 401) {
    bus.dispatchEvent(new Event('unauthorized'))
  }
  if (!res.ok) {
    const err = new Error(body?.error || ('http_' + res.status))
    err.status = res.status
    err.body = body
    throw err
  }
  return body
}

const GET = (url) => fetch(url, { credentials: 'include' }).then(handle)
const SEND = (method, url, body) => fetch(url, {
  method,
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: body !== undefined ? JSON.stringify(body) : undefined,
}).then(handle)

function qs(params) {
  const sp = new URLSearchParams()
  for (const k in params) {
    const v = params[k]
    if (v !== '' && v !== null && v !== undefined) sp.set(k, v)
  }
  const s = sp.toString()
  return s ? '?' + s : ''
}

export const api = {
  me:          () => GET(API_BASE + 'me'),
  clients:     () => GET(API_BASE + 'clients'),
  login:       (username, password, client, remember) => SEND('POST', API_BASE + 'login', { username, password, client, remember }),
  logout:      () => SEND('POST', API_BASE + 'logout'),

  aircraft:    () => GET(API_BASE + 'aircraft'),
  instructors: () => GET(API_BASE + 'instructors'),
  pilots:      () => GET(API_BASE + 'pilots'),
  purposes:    () => GET(API_BASE + 'flightpurposes'),
  airfields:   () => GET(API_BASE + 'airfields'),

  fleet:       (month) => GET(API_BASE + 'fleet' + qs({ month })),
  fleetDay:    (aircraft, date) => GET(API_BASE + 'fleet/day' + qs({ aircraft, date })),

  bookings:    (params = {}) => GET(API_BASE + 'bookings' + qs(params)),
  booking:     (id) => GET(API_BASE + 'bookings/' + id),
  availability:(params) => GET(API_BASE + 'availability' + qs(params)),

  create:      (body) => SEND('POST', API_BASE + 'bookings', body),
  update:      (id, body) => SEND('PATCH', API_BASE + 'bookings/' + id, body),
  remove:      (id) => SEND('DELETE', API_BASE + 'bookings/' + id),
}
