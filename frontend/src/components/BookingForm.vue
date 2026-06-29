<script setup>
import { ref, computed, watch } from 'vue'
import { api } from '../api.js'
import { ymd } from '../util.js'
import Icon from './Icon.vue'

const props = defineProps({
  md: Object,
  initial: { type: Object, default: null },
  bookingId: { type: Number, default: null },
})
const emit = defineEmits(['saved'])

const isEdit = computed(() => !!props.bookingId)

// --- Datum/Zeit ---
const today = ymd(new Date())
const startDate = ref(props.initial?.date || today)
const endDate = ref(props.initial?.endDate || props.initial?.date || today)
const startTime = ref(props.initial?.startTime || '10:00')
const endTime = ref(props.initial?.endTime || '12:00')
// Auswahl der Stammdaten
const aircraftId = ref(props.initial?.aircraftId || 0)
const fiId = ref(props.initial?.flightinstructor || 0)
const purposeId = ref(props.initial?.flightpurposeId || 0)
const airfieldId = ref(props.initial?.airfieldId || 0)
const description = ref(props.initial?.description || '')

const errors = ref([])
const busy = ref(false)
const autoPick = ref(!props.bookingId) // neue Buchung (auch vorbefüllt): ersten freien Block automatisch vorwählen

const selectedPurpose = computed(() => props.md.purposes.find((p) => p.id === purposeId.value))
// mehrtägig, sobald das Bis-Datum nach dem Von-Datum liegt
const isMulti = computed(() => endDate.value > startDate.value)

// Bis-Datum mit Von-Datum mitführen (Dauer in Tagen bleibt erhalten) – Standard: eintägig
watch(startDate, (nv, ov) => {
  if (!ov || !nv) return
  const delta = Math.round((new Date(nv + 'T00:00:00') - new Date(ov + 'T00:00:00')) / 86400000)
  if (!delta) return
  const e = new Date((endDate.value || nv) + 'T00:00:00')
  e.setDate(e.getDate() + delta)
  endDate.value = ymd(e)
})

// Standardwerte (nur Neu-Modus; beim Bearbeiten kommen die Werte aus der Buchung)
watch(() => props.md, (md) => {
  if (props.bookingId) return
  if (md.airfields?.length && !airfieldId.value) {
    const w = md.airfields.find((a) => a.id === 104); airfieldId.value = w ? w.id : md.airfields[0].id
  }
  if (md.purposes?.length && !purposeId.value) purposeId.value = md.purposes[0].id
}, { immediate: true, deep: true })

// ---- Verfügbarkeit laden ----
const avail = ref(null)        // Einzeltag
const availDays = ref([])      // mehrtägig: [{ ...availability, _date }]
const availLoading = ref(false)

function dayRange(a, b) {
  const out = []; const d = new Date(a + 'T00:00:00'); const end = new Date(b + 'T00:00:00')
  let i = 0
  while (d <= end && i < 31) { out.push(ymd(d)); d.setDate(d.getDate() + 1); i++ }
  return out
}

async function loadAvail() {
  availLoading.value = true
  try {
    if (isMulti.value) {
      const days = dayRange(startDate.value, endDate.value)
      const res = await Promise.all(days.map((dd) =>
        api.availability({ date: dd, aircraft: aircraftId.value, fi: fiId.value }).catch(() => null)))
      availDays.value = res.map((r, i) => (r ? { ...r, _date: days[i] } : null)).filter(Boolean)
      avail.value = availDays.value[0] || null
    } else {
      avail.value = await api.availability({ date: startDate.value, aircraft: aircraftId.value, fi: fiId.value })
      availDays.value = []
    }
  } catch {
    avail.value = null; availDays.value = []
  } finally {
    availLoading.value = false
  }
}
watch([startDate, endDate, aircraftId, fiId], loadAvail, { immediate: true })

// ---- Hilfen ----
function toMin(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m }
function toHHMM(m) { return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0') }
function dayShort(s) {
  const d = new Date(s + 'T00:00:00')
  const wd = new Intl.DateTimeFormat('de-DE', { weekday: 'short' }).format(d).replace('.', '')
  const [, mm, dd] = s.split('-')
  return `${wd} ${dd}.${mm}.`
}
// Kurzer Wochentag (z.B. "Di.") zum gewaehlten Datum – fuers Datumsfeld
function wd(s) {
  if (!s) return ''
  const d = new Date(s + 'T00:00:00')
  if (isNaN(d)) return ''
  return new Intl.DateTimeFormat('de-DE', { weekday: 'short' }).format(d)
}
// freie Fenster -> belegte Segmente innerhalb [s,e]
function busyIntervals(freeWindows, s, e) {
  const free = (freeWindows || []).map((w) => [toMin(w.start), toMin(w.end)]).sort((a, z) => a[0] - z[0])
  const segs = []; let cur = s
  for (const [fs, fe] of free) { if (fs > cur) segs.push([cur, fs]); cur = Math.max(cur, fe) }
  if (cur < e) segs.push([cur, e])
  return segs
}

// ===== Einzeltag-Ansicht =====
const dayBounds = computed(() => avail.value ? { s: toMin(avail.value.dayStart), e: toMin(avail.value.dayEnd) } : null)
function pct(min) { const b = dayBounds.value; return ((min - b.s) / (b.e - b.s)) * 100 }
function segsToPct(intervals) { return intervals.map(([s, e]) => ({ left: pct(s), width: pct(e) - pct(s) })) }
// Fluglehrer-Zustände -> Segmente mit CSS-Klasse (frei/auf Anfrage/Solo)
const STMAP = { frei: 's-frei', anfrage_direkt: 's-anfrageD', anfrage_absprache: 's-anfrageA', solo: 's-solo', ausgebucht: 's-ausgebucht' }
function stateSegsToPct(segments) {
  return (segments || []).map((s) => ({ left: pct(toMin(s.start)), width: pct(toMin(s.end)) - pct(toMin(s.start)), cls: STMAP[s.state] || 's-frei' }))
}

const aircraftLabel = computed(() => { const a = props.md.aircraft.find((x) => x.id === aircraftId.value); return a ? a.callsign : '' })
const fiLabel = computed(() => { const i = props.md.instructors.find((x) => x.id === fiId.value); return i ? i.name : '' })

const availRows = computed(() => {
  if (!avail.value || !dayBounds.value) return []
  const b = dayBounds.value, rows = []
  if (aircraftId.value && avail.value.aircraftFree) rows.push({ key: 'ac', mode: 'ac', t: 'Flugzeug', sub: aircraftLabel.value, busy: segsToPct(busyIntervals(avail.value.aircraftFree, b.s, b.e)) })
  if (fiId.value && avail.value.instructorSegments) rows.push({ key: 'fi', mode: 'fi', t: 'Fluglehrer', sub: fiLabel.value, states: stateSegsToPct(avail.value.instructorSegments) })
  return rows
})
const comboBusy = computed(() => (avail.value && dayBounds.value) ? segsToPct(busyIntervals(avail.value.freeSlots, dayBounds.value.s, dayBounds.value.e)) : [])
const selMarker = computed(() => {
  if (!dayBounds.value || !startTime.value || !endTime.value) return null
  const s = toMin(startTime.value), e = toMin(endTime.value)
  return e > s ? { left: pct(s), width: pct(e) - pct(s) } : null
})
const selFree = computed(() => {
  if (!avail.value || !startTime.value || !endTime.value) return null
  const s = toMin(startTime.value), e = toMin(endTime.value)
  if (e <= s) return null
  return (avail.value.freeSlots || []).some((w) => toMin(w.start) <= s && toMin(w.end) >= e)
})
const axisTicks = computed(() => {
  const b = dayBounds.value; if (!b) return []
  return [0, 1, 2, 3, 4].map((i) => String(Math.round((b.s + (b.e - b.s) * i / 4) / 60)).padStart(2, '0'))
})
// 2-Std-Block-Vorschlaege an den tatsaechlich freien Fenstern ausrichten
// (statt starrem 9-Uhr-Raster). Pro freiem Fenster werden ab Fensterbeginn
// 2-Std-Bloecke gelegt, solange sie ganz hineinpassen. Startzeit auf :00/:30
// gerundet (saubere Labels), nie vor 9 Uhr / Tagesbeginn.
const BLOCK_MIN = 120
function blkLabel(m) { const h = Math.floor(m / 60), mm = m % 60; return mm ? `${h}:${String(mm).padStart(2, '0')}` : `${h}` }
const freeBlocks = computed(() => {
  if (!avail.value || !dayBounds.value) return []
  const floor = Math.max(9 * 60, dayBounds.value.s)
  const dayEnd = dayBounds.value.e
  const out = []
  for (const w of (avail.value.freeSlots || [])) {
    let s = Math.ceil(Math.max(toMin(w.start), floor) / 30) * 30 // auf :00/:30 hoch
    const we = Math.min(toMin(w.end), dayEnd)
    while (s + BLOCK_MIN <= we) {
      out.push({ s, start: toHHMM(s), end: toHHMM(s + BLOCK_MIN), label: blkLabel(s) + '–' + blkLabel(s + BLOCK_MIN) })
      s += BLOCK_MIN
    }
  }
  return out
})
function pickBlock(b) { startTime.value = b.start; endTime.value = b.end; autoPick.value = false }

// Neue Buchung: solange der Nutzer nichts gewählt hat, ersten freien Block übernehmen
watch(freeBlocks, (blocks) => {
  if (autoPick.value && blocks.length) { startTime.value = blocks[0].start; endTime.value = blocks[0].end }
})

// ===== Mehrtages-Ansicht =====
const multiRows = computed(() => {
  const days = availDays.value
  return days.map((d, idx) => {
    const s = toMin(d.dayStart), e = toMin(d.dayEnd)
    const intervals = busyIntervals(d.freeSlots, s, e)
    const isFirst = idx === 0, isLast = idx === days.length - 1
    const rs = isFirst ? Math.max(s, toMin(startTime.value || d.dayStart)) : s
    const re = isLast ? Math.min(e, toMin(endTime.value || d.dayEnd)) : e
    const p = (m) => ((m - s) / (e - s)) * 100
    const conflict = re > rs && intervals.some(([bs, be]) => bs < re && be > rs)
    return {
      key: d._date, label: dayShort(d._date),
      busy: intervals.map(([bs, be]) => ({ left: p(bs), width: p(be) - p(bs) })),
      sel: re > rs ? { left: p(rs), width: p(re) - p(rs) } : null,
      conflict,
    }
  })
})
const periodFree = computed(() => multiRows.value.length > 0 && multiRows.value.every((r) => !r.conflict))

// ===== Vergleich: andere Fluglehrer / Flugzeuge (nur Einzeltag) =====
// Reiner Vergleich – aendert die Auswahl oben NICHT. Der gewaehlte Eintrag steht
// oben (hervorgehoben), die anderen darunter. Wird erst beim Ausklappen geladen.
const showCmpFi = ref(false)
const showCmpAc = ref(false)
const cmpFi = ref([])
const cmpAc = ref([])
const cmpFiLoading = ref(false)
const cmpAcLoading = ref(false)

async function loadCmpFi() {
  if (!avail.value || !dayBounds.value) return
  const b = dayBounds.value
  const list = props.md.instructors || []
  cmpFiLoading.value = true
  try {
    const res = await Promise.all(list.map((i) =>
      api.availability({ date: startDate.value, aircraft: 0, fi: i.id }).catch(() => null)))
    const rows = list.map((i, idx) => ({
      id: i.id, name: i.name,
      states: res[idx]?.instructorSegments ? stateSegsToPct(res[idx].instructorSegments) : [],
      selected: i.id === fiId.value,
    }))
    const sel = rows.find((r) => r.selected)
    cmpFi.value = sel ? [sel, ...rows.filter((r) => !r.selected)] : rows
  } finally {
    cmpFiLoading.value = false
  }
}

async function loadCmpAc() {
  if (!avail.value || !dayBounds.value) return
  const b = dayBounds.value
  const list = props.md.aircraft || []
  cmpAcLoading.value = true
  try {
    const res = await Promise.all(list.map((a) =>
      api.availability({ date: startDate.value, aircraft: a.id, fi: 0 }).catch(() => null)))
    const rows = list.map((a, idx) => ({
      id: a.id, name: a.callsign,
      busy: res[idx]?.aircraftFree ? segsToPct(busyIntervals(res[idx].aircraftFree, b.s, b.e)) : [],
      selected: a.id === aircraftId.value,
    }))
    const sel = rows.find((r) => r.selected)
    cmpAc.value = sel ? [sel, ...rows.filter((r) => !r.selected)] : rows
  } finally {
    cmpAcLoading.value = false
  }
}

function toggleCmpFi() { showCmpFi.value = !showCmpFi.value; if (showCmpFi.value && !cmpFi.value.length) loadCmpFi() }
function toggleCmpAc() { showCmpAc.value = !showCmpAc.value; if (showCmpAc.value && !cmpAc.value.length) loadCmpAc() }

// Bei Datums-/Auswahlwechsel offene Vergleiche gegen die neuen Tagesgrenzen auffrischen
watch(avail, () => {
  if (showCmpFi.value) loadCmpFi()
  if (showCmpAc.value) loadCmpAc()
})

// ---- Speichern ----
async function save() {
  if (busy.value) return
  errors.value = []
  if (!startTime.value || !endTime.value) { errors.value = ['Bitte Start- und Endzeit wählen']; return }
  busy.value = true
  const body = {
    aircraftId: aircraftId.value,
    airfieldId: airfieldId.value,
    flightpurposeId: purposeId.value,
    flightinstructor: fiId.value || null,
    start: `${startDate.value} ${startTime.value}`,
    end: `${endDate.value} ${endTime.value}`,
    description: description.value,
  }
  try {
    if (isEdit.value) await api.update(props.bookingId, body)
    else { await api.create(body); description.value = ''; startTime.value = ''; endTime.value = '' }
    emit('saved')
  } catch (e) {
    errors.value = e.body?.errors || [e.body?.error || 'Speichern fehlgeschlagen']
  } finally {
    busy.value = false
  }
}
function purposeIcon(name) {
  const p = (name || '').toLowerCase()
  if (p.includes('schul')) return 'training'
  if (p.includes('charter')) return 'charter'
  if (p.includes('wartung')) return 'wrench'
  return 'plane'
}
defineExpose({ submit: save })
</script>

<template>
  <div class="body bookform">
    <!-- Flugzeug + Fluglehrer -->
    <div class="ftitle"><Icon name="plane" /> Flugzeug &amp; Fluglehrer</div>
    <div class="formgroup">
      <div class="frow iconrow">
        <span class="ri"><Icon name="plane" /></span>
        <label>Flugzeug</label>
        <select v-model.number="aircraftId">
          <option :value="0">Bitte wählen…</option>
          <option v-for="a in md.aircraft" :key="a.id" :value="a.id">{{ a.type }} ({{ a.callsign }})</option>
        </select>
      </div>
      <div class="frow iconrow">
        <span class="ri"><Icon name="user" /></span>
        <label>Fluglehrer</label>
        <select v-model.number="fiId">
          <option :value="0">(keiner)</option>
          <option v-for="i in md.instructors" :key="i.id" :value="i.id">{{ i.name }}</option>
        </select>
      </div>
    </div>

    <!-- Zeitraum: 2 Zeilen – Datum & Uhrzeit als getrennte Felder -->
    <div class="ftitle"><Icon name="clock" /> Zeitraum</div>
    <div class="formgroup">
      <div class="frow">
        <label>Von</label>
        <div class="dwrap"><span class="wd">{{ wd(startDate) }}</span><input type="date" class="pillin" v-model="startDate" /></div>
        <input type="time" class="pillin" v-model="startTime" @change="autoPick = false" />
      </div>
      <div class="frow">
        <label>Bis</label>
        <div class="dwrap"><span class="wd">{{ wd(endDate) }}</span><input type="date" class="pillin" v-model="endDate" :min="startDate" /></div>
        <input type="time" class="pillin" v-model="endTime" @change="autoPick = false" />
      </div>
    </div>

    <!-- Verfügbarkeit -->
    <div v-if="availLoading" class="ftitle"><Icon name="chart" /> Verfügbarkeit</div>
    <div v-if="availLoading" class="muted">wird geladen…</div>

    <!-- Einzeltag: freie 2-Std-Blöcke ZUERST, dann die Verfügbarkeits-Grafik -->
    <template v-else-if="!isMulti && avail && dayBounds">
      <!-- Verfuegbarkeit/freie Bloecke sind erst sinnvoll, wenn ein Flugzeug gewaehlt ist.
           Ohne Flugzeug liefert die API keine Einschraenkung -> alles "frei" (irrefuehrend). -->
      <template v-if="aircraftId">
        <div v-if="freeBlocks.length" class="ftitle"><Icon name="blocks" /> Freie 2-Std-Blöcke</div>
        <div v-if="freeBlocks.length" class="tblocks">
          <div v-for="b in freeBlocks" :key="b.s" class="tblk" :class="{ sel: startTime === b.start && endTime === b.end }" @click="pickBlock(b)">{{ b.label }}</div>
        </div>

        <div class="ftitle"><Icon name="chart" /> Verfügbarkeit</div>
        <div class="av3box">
          <div v-for="r in availRows" :key="r.key" class="av3row">
            <div class="av3lab">{{ r.t }}<br><small>{{ r.sub }}</small></div>
            <div class="av3bar" :class="{ nb: r.mode === 'fi' }">
              <template v-if="r.mode === 'fi'">
                <div v-for="(seg, i) in r.states" :key="i" class="av3seg" :class="seg.cls" :style="{ left: seg.left + '%', width: seg.width + '%' }"></div>
              </template>
              <template v-else>
                <div v-for="(seg, i) in r.busy" :key="i" class="av3seg" :style="{ left: seg.left + '%', width: seg.width + '%' }"></div>
              </template>
            </div>
          </div>
          <div class="av3row">
            <div class="av3lab strong">{{ availRows.length > 1 ? 'Beide frei' : 'Frei' }}</div>
            <div class="av3bar combo">
              <div v-for="(seg, i) in comboBusy" :key="i" class="av3seg" :style="{ left: seg.left + '%', width: seg.width + '%' }"></div>
              <div v-if="selMarker" class="av3sel" :class="{ bad: selFree === false }" :style="{ left: selMarker.left + '%', width: selMarker.width + '%' }"></div>
            </div>
          </div>
          <div class="av3axis"><span v-for="(t, i) in axisTicks" :key="i">{{ t }}</span></div>
        </div>
        <!-- Nur Warnung bei Konflikt; "frei" waere redundant zum ausgewaehlten Block oben. -->
        <div v-if="selMarker && selFree === false" class="av3verdict bad">⚠ {{ startTime }}–{{ endTime }} ist (teils) belegt.</div>
        <div v-if="fiId" class="av3legend">
          <span class="note">Fluglehrer: Vollton = direkt buchbar · gestreift = nach Absprache · grau = nicht buchbar</span>
          <span><i class="s-frei"></i>frei</span><span><i class="s-anfrageD"></i>auf Anfrage (buchbar)</span><span><i class="s-anfrageA"></i>n. Absprache (erst nach Freigabe FI buchbar)</span><span><i class="s-solo"></i>Solo</span><span><i class="s-ausgebucht"></i>verfügbar, aber ausgebucht</span><span><i class="nb"></i>nicht buchbar</span>
        </div>
      </template>
      <template v-else>
        <div class="ftitle"><Icon name="chart" /> Verfügbarkeit</div>
        <div class="muted">Bitte zuerst ein Flugzeug wählen.</div>
      </template>

      <!-- Vergleich anderer Flugzeuge -->
      <div class="cmptoggle" :class="{ open: showCmpAc }" @click="toggleCmpAc"><span>Andere Flugzeuge vergleichen</span><span class="ar">{{ showCmpAc ? '▾' : '▸' }}</span></div>
      <div v-if="showCmpAc" class="cmppanel">
        <div v-if="cmpAcLoading" class="cmpempty">wird geladen…</div>
        <template v-else>
          <div v-for="r in cmpAc" :key="r.id" class="av3row" :class="{ cmpsel: r.selected }">
            <div class="av3lab">{{ r.name }}</div>
            <div class="av3bar"><div v-for="(seg, i) in r.busy" :key="i" class="av3seg" :style="{ left: seg.left + '%', width: seg.width + '%' }"></div></div>
          </div>
          <div class="av3axis"><span v-for="(t, i) in axisTicks" :key="i">{{ t }}</span></div>
        </template>
      </div>

      <!-- Vergleich anderer Fluglehrer (reine Ansicht, aendert die Auswahl oben nicht) -->
      <div class="cmptoggle" :class="{ open: showCmpFi }" @click="toggleCmpFi"><span>Andere Fluglehrer vergleichen</span><span class="ar">{{ showCmpFi ? '▾' : '▸' }}</span></div>
      <div v-if="showCmpFi" class="cmppanel">
        <div v-if="cmpFiLoading" class="cmpempty">wird geladen…</div>
        <template v-else>
          <div v-for="r in cmpFi" :key="r.id" class="av3row" :class="{ cmpsel: r.selected }">
            <div class="av3lab">{{ r.name }}</div>
            <div class="av3bar nb"><div v-for="(seg, i) in r.states" :key="i" class="av3seg" :class="seg.cls" :style="{ left: seg.left + '%', width: seg.width + '%' }"></div></div>
          </div>
          <div class="av3axis"><span v-for="(t, i) in axisTicks" :key="i">{{ t }}</span></div>
        </template>
      </div>
    </template>

    <!-- Mehrtägig: ein Zeitstrahl pro Tag (nur mit gewähltem Flugzeug aussagekräftig) -->
    <template v-else-if="isMulti && aircraftId && multiRows.length">
      <div class="ftitle"><Icon name="chart" /> Verfügbarkeit · gesamter Zeitraum</div>
      <div class="av3box">
        <div v-for="r in multiRows" :key="r.key" class="av3row">
          <div class="av3lab strong">{{ r.label }}</div>
          <div class="av3bar combo">
            <div v-for="(seg, i) in r.busy" :key="i" class="av3seg" :style="{ left: seg.left + '%', width: seg.width + '%' }"></div>
            <div v-if="r.sel" class="av3sel" :class="{ bad: r.conflict }" :style="{ left: r.sel.left + '%', width: r.sel.width + '%' }"></div>
          </div>
        </div>
        <div class="av3axis"><span>09</span><span>12</span><span>15</span><span>18</span><span>21</span></div>
        <div class="av3lg"><i class="lg-sel"></i>reserviert<i class="lg-busy"></i>belegt</div>
      </div>
      <div class="av3verdict" :class="periodFree ? 'ok' : 'bad'">
        <template v-if="periodFree">✓ Im gesamten Zeitraum frei.</template>
        <template v-else>⚠ Zeitraum ist nicht durchgehend frei.</template>
      </div>
    </template>
    <template v-else-if="isMulti && !aircraftId">
      <div class="ftitle"><Icon name="chart" /> Verfügbarkeit · gesamter Zeitraum</div>
      <div class="muted">Bitte zuerst ein Flugzeug wählen.</div>
    </template>

    <!-- Flugzweck -->
    <div class="ftitle"><Icon name="training" /> Flugzweck</div>
    <div class="segpurpose">
      <div v-for="p in md.purposes" :key="p.id" class="pp" :class="{ on: purposeId === p.id }" @click="purposeId = p.id">
        <Icon :name="purposeIcon(p.name)" />
        <span>{{ p.name }}</span>
      </div>
    </div>
    <div v-if="selectedPurpose && selectedPurpose.isTraining && !fiId" class="muted">Für Schulflüge bitte einen Fluglehrer wählen.</div>

    <!-- Flugplatz -->
    <div class="ftitle"><Icon name="pin" /> Flugplatz</div>
    <div class="formgroup">
      <div class="frow iconrow">
        <span class="ri"><Icon name="pin" /></span>
        <label>Ziel</label>
        <select v-model.number="airfieldId">
          <option v-for="a in md.airfields" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
      </div>
    </div>

    <!-- Beschreibung -->
    <div class="ftitle"><Icon name="note" /> Beschreibung</div>
    <div class="formgroup">
      <textarea class="desc" v-model="description" placeholder="z.B. Platzrunden, Landetraining …"></textarea>
    </div>

    <div v-if="errors.length" class="errbox">
      {{ isEdit ? 'Änderung nicht möglich:' : 'Reservierung nicht möglich:' }}
      <ul><li v-for="(e, idx) in errors" :key="idx">{{ e }}</li></ul>
    </div>

    <button class="bigbtn" :disabled="busy" @click="save">{{ isEdit ? 'Änderungen speichern' : 'Reservierung speichern' }}</button>
  </div>
</template>
