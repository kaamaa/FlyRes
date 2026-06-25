<script setup>
import { ref, computed, watch } from 'vue'
import { api } from '../api.js'
import { ymd, germanDay } from '../util.js'

const props = defineProps({
  md: Object,
  initial: { type: Object, default: null }, // Edit-Daten (aircraftId, flightinstructor, …) oder null
  bookingId: { type: Number, default: null }, // gesetzt => Bearbeiten (PATCH)
})
const emit = defineEmits(['saved'])

const isEdit = computed(() => !!props.bookingId)

function parseDate(s) {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, m - 1, d)
}

const date = ref(props.initial ? parseDate(props.initial.date) : new Date())
const aircraftId = ref(props.initial?.aircraftId || 0)
const fiId = ref(props.initial?.flightinstructor || 0)
const purposeId = ref(props.initial?.flightpurposeId || 0)
const airfieldId = ref(props.initial?.airfieldId || 0)
const description = ref(props.initial?.description || '')
const startTime = ref(props.initial?.startTime || '')
const endTime = ref(props.initial?.endTime || '')

const avail = ref(null)
const availLoading = ref(false)
const errors = ref([])
const busy = ref(false)

const dateLabel = computed(() => germanDay(date.value))
const selectedPurpose = computed(() => props.md.purposes.find((p) => p.id === purposeId.value))

// Standardwerte nur im Neu-Modus aus den Stammdaten setzen
watch(() => props.md, (md) => {
  if (props.initial) return
  if (md.airfields?.length && !airfieldId.value) {
    const worms = md.airfields.find((a) => a.id === 104)
    airfieldId.value = worms ? worms.id : md.airfields[0].id
  }
  if (md.purposes?.length && !purposeId.value) purposeId.value = md.purposes[0].id
}, { immediate: true, deep: true })

function stepDay(n) {
  const d = new Date(date.value)
  d.setDate(d.getDate() + n)
  date.value = d
}

async function loadAvail() {
  availLoading.value = true
  try {
    avail.value = await api.availability({ date: ymd(date.value), aircraft: aircraftId.value, fi: fiId.value })
  } catch {
    avail.value = null
  } finally {
    availLoading.value = false
  }
}
watch([date, aircraftId, fiId], loadAvail, { immediate: true })

function pickSlot(s) {
  startTime.value = s.start
  endTime.value = s.end
}

async function save() {
  if (busy.value) return
  errors.value = []
  if (!startTime.value || !endTime.value) {
    errors.value = ['Bitte Start- und Endzeit wählen (z.B. über einen freien Slot)']
    return
  }
  busy.value = true
  const body = {
    aircraftId: aircraftId.value,
    airfieldId: airfieldId.value,
    flightpurposeId: purposeId.value,
    flightinstructor: fiId.value || null,
    start: `${ymd(date.value)} ${startTime.value}`,
    end: `${ymd(date.value)} ${endTime.value}`,
    description: description.value,
  }
  try {
    if (isEdit.value) {
      await api.update(props.bookingId, body)
    } else {
      await api.create(body)
      description.value = ''
      startTime.value = ''
      endTime.value = ''
    }
    emit('saved')
  } catch (e) {
    errors.value = e.body?.errors || [e.body?.error || 'Speichern fehlgeschlagen']
  } finally {
    busy.value = false
  }
}

defineExpose({ submit: save })
</script>

<template>
  <div class="body">
    <!-- Tag -->
    <div class="daystep">
      <button @click="stepDay(-1)">‹</button>
      <div class="daysel">{{ dateLabel }}</div>
      <button @click="stepDay(1)">›</button>
    </div>

    <!-- Flugzeug + Fluglehrer -->
    <div class="picker2">
      <div class="pk">
        <div class="pk-lbl">Flugzeug</div>
        <select v-model.number="aircraftId">
          <option :value="0">Bitte wählen…</option>
          <option v-for="a in md.aircraft" :key="a.id" :value="a.id">{{ a.type }} ({{ a.callsign }})</option>
        </select>
      </div>
      <div class="pk">
        <div class="pk-lbl">Fluglehrer</div>
        <select v-model.number="fiId">
          <option :value="0">(keiner)</option>
          <option v-for="i in md.instructors" :key="i.id" :value="i.id">{{ i.name }}</option>
        </select>
      </div>
    </div>

    <!-- Freie Slots -->
    <div class="ftitle">Freie Zeitfenster</div>
    <div v-if="availLoading" class="muted">Verfügbarkeit wird geladen…</div>
    <template v-else-if="avail">
      <div v-if="avail.freeSlots.length" class="slots">
        <div v-for="s in avail.freeSlots" :key="s.start"
             class="slot" :class="{ on: startTime === s.start && endTime === s.end }"
             @click="pickSlot(s)">
          <div class="slot-t">{{ s.start }} – {{ s.end }}</div>
          <div class="slot-d">{{ Math.floor(s.minutes / 60) }}:{{ String(s.minutes % 60).padStart(2, '0') }} Std frei</div>
        </div>
      </div>
      <div v-else class="muted">Kein gemeinsames freies Fenster an diesem Tag.</div>
    </template>

    <!-- Zeit (manuell anpassbar) -->
    <div class="ftitle">Zeitraum</div>
    <div class="formgroup">
      <div class="frow"><label>Von</label><input type="time" v-model="startTime" /></div>
      <div class="frow"><label>Bis</label><input type="time" v-model="endTime" /></div>
    </div>

    <!-- Flugzweck -->
    <div class="ftitle">Flugzweck</div>
    <div class="segpurpose">
      <div v-for="p in md.purposes" :key="p.id" class="pp" :class="{ on: purposeId === p.id }" @click="purposeId = p.id">
        {{ p.name }}
      </div>
    </div>
    <div v-if="selectedPurpose && selectedPurpose.isTraining && !fiId" class="muted">
      Für Schulflüge bitte einen Fluglehrer wählen.
    </div>

    <!-- Flugplatz -->
    <div class="ftitle">Flugplatz</div>
    <div class="formgroup">
      <div class="frow">
        <label>Ziel</label>
        <select v-model.number="airfieldId">
          <option v-for="a in md.airfields" :key="a.id" :value="a.id">{{ a.name }}</option>
        </select>
      </div>
    </div>

    <!-- Beschreibung -->
    <div class="ftitle">Beschreibung</div>
    <div class="formgroup">
      <textarea class="desc" v-model="description" placeholder="z.B. Platzrunden, Landetraining …"></textarea>
    </div>

    <!-- Fehler -->
    <div v-if="errors.length" class="errbox">
      {{ isEdit ? 'Änderung nicht möglich:' : 'Reservierung nicht möglich:' }}
      <ul><li v-for="(e, idx) in errors" :key="idx">{{ e }}</li></ul>
    </div>

    <button class="bigbtn" :disabled="busy" @click="save">
      {{ isEdit ? 'Änderungen speichern' : 'Reservierung speichern' }}
    </button>
  </div>
</template>
