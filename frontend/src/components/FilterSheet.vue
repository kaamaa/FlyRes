<script setup>
import { ref } from 'vue'

const props = defineProps({ md: Object, filters: Object })
const emit = defineEmits(['close', 'apply'])

const f = ref({ ...props.filters })
const open = ref('')
const views = [
  ['today', 'Heute'], ['tomorrow', 'Morgen'],
  ['thisweekend', 'Dieses WE'], ['nextweekend', 'Nächstes WE'],
  ['thisweek', 'Diese Woche'], ['weekafter', 'Nächste Woche'],
  ['thismonth', 'Dieser Monat'], ['all', 'Alle'],
]

function toggle(k) { open.value = open.value === k ? '' : k }
function nameOf(list, id, key = 'name') {
  const x = list.find((i) => i.id === id)
  return x ? (x[key] || x.callsign || x.type) : 'Alle'
}
function reset() { f.value = { view: 'all', aircraft: 0, fi: 0, pilot: 0 } }
</script>

<template>
  <div class="overlay" @click="emit('close')"></div>
  <div class="sheet">
    <div class="sheet-grip"></div>
    <div class="sheet-head">
      <button class="action" @click="reset">Zurücksetzen</button>
      <span class="sheet-title">Filter</span>
      <button class="action bold" @click="emit('apply', { ...f })">Fertig</button>
    </div>
    <div class="sheet-body">
      <div class="acc">
        <div class="acc-row" @click="toggle('view')">
          <span class="acc-k">Zeitraum</span>
          <span class="acc-v">{{ (views.find((v) => v[0] === f.view) || [, 'Alle'])[1] }} ▾</span>
        </div>
        <div v-show="open === 'view'" class="fseg">
          <button v-for="v in views" :key="v[0]" :class="{ on: f.view === v[0] }" @click="f.view = v[0]">{{ v[1] }}</button>
        </div>
      </div>

      <div class="acc">
        <div class="acc-row" @click="toggle('ac')">
          <span class="acc-k">Flugzeug</span>
          <span class="acc-v">{{ f.aircraft ? nameOf(md.aircraft, f.aircraft, 'callsign') : 'Alle' }} ▾</span>
        </div>
        <div v-show="open === 'ac'" class="flist">
          <div class="fli" :class="{ on: !f.aircraft }" @click="f.aircraft = 0">Alle</div>
          <div v-for="a in md.aircraft" :key="a.id" class="fli" :class="{ on: f.aircraft === a.id }" @click="f.aircraft = a.id">
            {{ a.type }} ({{ a.callsign }})
          </div>
        </div>
      </div>

      <div class="acc">
        <div class="acc-row" @click="toggle('fi')">
          <span class="acc-k">Fluglehrer</span>
          <span class="acc-v">{{ f.fi ? nameOf(md.instructors, f.fi) : 'Alle' }} ▾</span>
        </div>
        <div v-show="open === 'fi'" class="flist">
          <div class="fli" :class="{ on: !f.fi }" @click="f.fi = 0">Alle</div>
          <div v-for="i in md.instructors" :key="i.id" class="fli" :class="{ on: f.fi === i.id }" @click="f.fi = i.id">{{ i.name }}</div>
        </div>
      </div>

      <div class="acc">
        <div class="acc-row" @click="toggle('pilot')">
          <span class="acc-k">Pilot</span>
          <span class="acc-v">{{ f.pilot ? nameOf(md.pilots, f.pilot) : 'Alle' }} ▾</span>
        </div>
        <div v-show="open === 'pilot'" class="flist">
          <div class="fli" :class="{ on: !f.pilot }" @click="f.pilot = 0">Alle</div>
          <div v-for="p in md.pilots" :key="p.id" class="fli" :class="{ on: f.pilot === p.id }" @click="f.pilot = p.id">{{ p.name }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
