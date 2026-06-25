// Kleine Darstellungs-Helfer (Badge-Farbe, Balkenfarbe).

export function badgeClass(purpose) {
  const p = (purpose || '').toLowerCase()
  if (p.includes('schul')) return 'b-schulung'
  if (p.includes('privat')) return 'b-privat'
  if (p.includes('charter')) return 'b-charter'
  return 'b-default'
}

export function barColor(b) {
  return b && b.isTraining ? '#ff9500' : '#0a84ff'
}

export function ymd(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

export function germanDay(date) {
  return new Intl.DateTimeFormat('de-DE', {
    weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
  }).format(date)
}

// Tages-Trennerlabel aus dem API-Datumstext ("Mittwoch 25.06.2026") bauen:
//   "Heute · Mi, 25. Juni" / "Morgen · Do, 26. Juni" / "Sa, 28. Juni"
export function dayLabel(apiDate) {
  const m = /(\d{1,2})\.(\d{1,2})\.(\d{4})/.exec(apiDate || '')
  if (!m) return apiDate || ''
  const d = new Date(+m[3], +m[2] - 1, +m[1])

  const today = new Date()
  const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diffDays = Math.round((startOf(d) - startOf(today)) / 86400000)
  const rel = diffDays === 0 ? 'Heute' : diffDays === 1 ? 'Morgen' : diffDays === -1 ? 'Gestern' : null

  const wd = new Intl.DateTimeFormat('de-DE', { weekday: 'short' }).format(d).replace('.', '')
  const dm = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long', year: 'numeric' }).format(d)
  return rel ? `${rel} · ${wd}, ${dm}` : `${wd}, ${dm}`
}
