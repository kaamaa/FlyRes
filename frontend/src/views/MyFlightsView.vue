<script setup>
import { ref, computed, watch } from 'vue'
import { api } from '../api.js'
import { badgeClass, barColor, dayLabel } from '../util.js'
import DayHeader from '../components/DayHeader.vue'

const emit = defineEmits(['open', 'reserve', 'logout'])
const tab = ref('mine')
const items = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    items.value = await api.bookings({ view: tab.value })
  } finally {
    loading.value = false
  }
}
watch(tab, load, { immediate: true })

const openItems = computed(() => items.value.filter((b) => b.fiOpen)) // MVP 2: offene Buchungen

const grouped = computed(() => {
  const g = []
  let last = null
  for (const b of items.value) {
    if (b.date !== last) { g.push({ date: b.date, items: [] }); last = b.date }
    g[g.length - 1].items.push(b)
  }
  return g
})
</script>

<template>
  <div class="nav">
    <div class="row"><button class="action muted" @click="emit('logout')">Abmelden</button><button class="action bold" @click="emit('reserve')">+ Neu</button></div>
    <div class="large">Meine Flüge</div>
  </div>
  <div class="seg">
    <button :class="{ on: tab === 'mine' }" @click="tab = 'mine'">Kommende</button>
    <button :class="{ on: tab === 'mine_history' }" @click="tab = 'mine_history'">Vergangene</button>
  </div>
  <div class="body">
    <div v-if="loading" class="center">Lädt…</div>
    <div v-else-if="!items.length" class="center">Keine Reservierungen</div>
    <div v-else>
      <a v-if="openItems.length" class="fiopen-banner" @click="emit('open', openItems[0].id)">
        <span class="bi">⚠️</span>
        <span class="bt"><b>{{ openItems.length }} {{ openItems.length === 1 ? 'Reservierung' : 'Reservierungen' }} ohne Fluglehrer</b><small>Noch kein Fluglehrer bestätigt – bitte selbst darum kümmern.</small></span>
        <span class="ba">›</span>
      </a>
      <div v-for="g in grouped" :key="g.date">
        <DayHeader :label="dayLabel(g.date)" :count="g.items.length" />
        <div v-for="b in g.items" :key="b.id" class="mycard" @click="emit('open', b.id)">
          <div class="head" :style="{ background: barColor(b) }">
            <div class="pl">{{ b.aircraft }}</div>
          </div>
          <div class="body2">
            <div class="tt">{{ b.start }} – {{ b.end }}</div>
            <div class="meta">
              {{ b.pilot }}<br>
              <span class="badge" :class="badgeClass(b.purpose)" style="margin-top:4px;">
                {{ b.purpose }}<template v-if="!b.fiOpen && b.instructor"> · FI {{ b.instructor }}</template>
              </span>
              <span v-if="b.fiOpen" class="fiopen-badge" style="margin-left:6px;">⚠ Fluglehrer offen</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
