<script setup>
import { ref, watch } from 'vue'
import { api } from '../api.js'
import { badgeClass } from '../util.js'

// status (0..5 Auslastungsstufe) ist dem Aufrufer (FleetView) schon bekannt -> als Prop.
const props = defineProps({
  aircraftId: Number,
  callsign: String,
  type: String,
  date: String,     // YYYY-MM-DD
  status: Number,    // 0 leer .. 5 ausgebucht
})
const emit = defineEmits(['close', 'open', 'reserve'])

const data = ref(null)
const loading = ref(true)

async function load() {
  loading.value = true
  data.value = null
  try {
    data.value = await api.fleetDay(props.aircraftId, props.date)
  } finally {
    loading.value = false
  }
}
watch(() => [props.aircraftId, props.date], load, { immediate: true })

const statusText = ['frei', 'wenig belegt', 'mittel belegt', 'voll', 'sehr voll', 'ausgebucht']

// 'd.m.Y' -> 'So 21.06.' (Wochentag + Tag.Monat) für die Richtungs-Chips mehrtägiger Buchungen.
const WD = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa']
function dlabel(d) {
  const [dd, mm, yy] = String(d).split('.')
  const dt = new Date(+yy, +mm - 1, +dd)
  return WD[dt.getDay()] + ' ' + dd + '.' + mm + '.'
}

// Zeitanzeige bezogen auf DIESEN Tag (startDay/endDay sind gesetzt, wenn die
// Buchung an einem anderen Tag beginnt/endet -> mehrtägig):
//   beginnt+endet heute -> "09:00–11:00", nur Start heute -> "ab 09:00",
//   nur Ende heute -> "bis 13:00", Zwischentag -> "ganztägig".
function timeLabel(b) {
  const startsToday = !b.startDay
  const endsToday = !b.endDay
  if (startsToday && endsToday) return b.start + '–' + b.end
  if (startsToday) return 'ab ' + b.start
  if (endsToday) return 'bis ' + b.end
  return 'ganztägig'
}
</script>

<template>
  <div class="overlay" @click="emit('close')"></div>
  <div class="sheet">
    <div class="sheet-grip"></div>
    <div class="sheet-head">
      <div class="sheet-title">{{ callsign }} <small style="color:var(--ios-gray);font-weight:600;">{{ type }}</small></div>
      <button class="action" @click="emit('close')">Fertig</button>
    </div>

    <div class="sheet-body">
      <div class="fd-day">
        <span>{{ data ? data.label : date }}</span>
        <span class="fd-status"><i :class="'lvl-' + status"></i>{{ statusText[status] || '' }}</span>
      </div>

      <div v-if="loading" class="center" style="padding:24px;">Lädt…</div>

      <template v-else-if="data">
        <div v-if="!data.bookings.length" class="cmpempty" style="padding:22px 16px;">
          Keine Reservierung – der ganze Tag ist frei.
        </div>

        <div v-else class="formgroup" style="margin:4px 16px 0;">
          <button v-for="b in data.bookings" :key="b.id" class="fd-row" @click="emit('open', b.id)">
            <div class="fd-time"><b>{{ timeLabel(b) }}</b></div>
            <div class="fd-info">
              <span class="badge" :class="badgeClass(b.purpose)">{{ b.purpose }}</span>
              <span class="fd-pilot">{{ b.pilot }}<template v-if="b.instructor"> · FI: {{ b.instructor }}</template></span>
              <span v-if="b.startDay || b.endDay" class="fd-chips">
                <span v-if="b.startDay" class="fd-chip ghost">↤ seit {{ dlabel(b.startDay) }}</span>
                <span v-if="b.endDay" class="fd-chip">↦ bis {{ dlabel(b.endDay) }}</span>
              </span>
            </div>
            <span class="fd-chev">›</span>
          </button>
        </div>
      </template>

      <button class="bigbtn" @click="emit('reserve', { aircraftId, date })">Reservieren</button>
    </div>
  </div>
</template>
