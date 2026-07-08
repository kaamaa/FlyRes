<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { api } from '../api.js'
import FleetDaySheet from '../components/FleetDaySheet.vue'

const emit = defineEmits(['open', 'reserve'])

const data = ref(null)
const month = ref(null)
const loading = ref(true)
const sel = ref(null) // angetippte Zelle -> Tagesdetail-Sheet

function openCell(a, d, status) {
  sel.value = { aircraftId: a.id, callsign: a.callsign, type: a.type, date: d.date, status }
}

// Akzentpunkt je Flugzeug (deterministisch nach Position).
const DOTS = ['#34c759', '#0a84ff', '#ff9f0a', '#bf5af2', '#ff375f', '#5ac8fa', '#ffd60a', '#ff6482']
const dotColor = (i) => DOTS[i % DOTS.length]

const wrap = ref(null)     // Scroll-Container
const rotated = ref(false) // true -> Flugzeug-Beschriftung um 90° gedreht

// Spalten-Mindestbreite: waagerecht 56px, senkrecht (gedreht) reichen 32px.
const COL_MIN = 56
const COL_MIN_ROT = 32
const DAY_COL = 58

async function load(m) {
  loading.value = true
  try {
    const d = await api.fleet(m || undefined)
    data.value = d
    month.value = d.month
  } finally {
    loading.value = false
  }
}

// Passen alle Flugzeuge waagerecht nebeneinander? Wenn nicht -> Labels drehen.
// Entscheidung basiert auf der UN-gedrehten Breite (56px), damit sie stabil ist
// und nicht zwischen gedreht/ungedreht hin- und herspringt.
function checkFit() {
  const el = wrap.value
  const n = data.value ? data.value.aircraft.length : 0
  if (!el || !n) { rotated.value = false; return }
  const w = el.clientWidth
  if (!w) return // noch nicht gelayoutet -> ResizeObserver meldet sich erneut
  // Breite, die die Flugzeuge bei normaler (56px) Beschriftung bräuchten:
  rotated.value = (DAY_COL + n * COL_MIN) > w + 0.5
}

// ResizeObserver statt einmaligem Messen: feuert zuverlässig, sobald der
// Container eine (neue) Breite hat – behebt das "manchmal noch quer"-Problem,
// wenn beim ersten Messen die Breite noch 0/unfertig war.
let ro = null

const gridStyle = computed(() => {
  const n = data.value ? data.value.aircraft.length : 0
  const days = data.value ? data.value.days.length : 0
  const col = rotated.value ? COL_MIN_ROT : COL_MIN
  const s = { gridTemplateColumns: DAY_COL + 'px repeat(' + n + ', minmax(' + col + 'px, 1fr))' }
  // Kopfzeile höher als die Tageszeilen: waagerecht für Punkt+Kennung+Typ, gedreht für senkrechten Text.
  s.gridTemplateRows = (rotated.value ? '94px' : '48px') + ' repeat(' + days + ', 34px)'
  return s
})

watch(data, () => nextTick(checkFit))
// Den Scroll-Container beobachten, sobald er (neu) gemountet wird.
watch(wrap, (el) => { if (ro) { ro.disconnect(); if (el) ro.observe(el) } })
onMounted(() => { ro = new ResizeObserver(() => checkFit()); load() })
onUnmounted(() => { if (ro) ro.disconnect() })

function go(m) { if (m) load(m) }
const pad = (n) => String(n).padStart(2, '0')
</script>

<template>
  <div class="nav">
    <div class="fmnav">
      <button class="mbtn" :disabled="!data || loading" @click="go(data && data.prev)">‹</button>
      <div class="mtitle">{{ data ? data.label : '…' }}<small>Flottenauslastung</small></div>
      <button class="mbtn" :disabled="!data || loading" @click="go(data && data.next)">›</button>
    </div>
    <div class="fscale">frei<span class="fscale-bar"></span>voll</div>
  </div>

  <div v-if="loading && !data" class="center">Lädt…</div>
  <div v-else-if="data && !data.aircraft.length" class="center">Keine Flugzeuge</div>

  <div v-else-if="data" ref="wrap" class="fleetwrap">
    <!-- Gedreht: Flugzeuge = Spalten (oben), Tage = Zeilen (untereinander).
         Bei vielen Flugzeugen behalten die Spalten ihre Mindestbreite -> horizontal scrollbar.
         Passen nicht alle nebeneinander, werden die Flugzeug-Labels um 90° gedreht (.vlabels).
         Kopfzeile (Flugzeuge) und linke Tagesspalte bleiben sticky stehen. -->
    <div class="fgrid" :class="{ vlabels: rotated }" :style="gridStyle">
      <div class="fcorner"></div>
      <div v-for="(a, ai) in data.aircraft" :key="a.id" class="fch">
        <span class="fdot" :style="{ background: dotColor(ai) }"></span>
        <span class="cs">{{ a.callsign }}</span><span class="ty">{{ a.type }}</span>
      </div>
      <template v-for="(d, di) in data.days" :key="d.day">
        <div class="frh" :class="{ we: d.weekend, today: d.today }">
          <span class="wd">{{ d.wd }}</span><span class="dn">{{ pad(d.day) }}</span>
        </div>
        <div v-for="a in data.aircraft" :key="a.id" class="fcell"
             :class="['lvl-' + a.days[di], { today: d.today }]"
             @click="openCell(a, d, a.days[di])"></div>
      </template>
    </div>
  </div>

  <FleetDaySheet v-if="sel" v-bind="sel"
                 @close="sel = null"
                 @open="(id) => { sel = null; emit('open', id) }"
                 @reserve="(p) => { sel = null; emit('reserve', p) }" />
</template>
