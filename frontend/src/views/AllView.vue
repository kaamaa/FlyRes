<script setup>
import { ref, computed, watch } from 'vue'
import { api } from '../api.js'
import { dayLabel } from '../util.js'
import BookingCard from '../components/BookingCard.vue'
import DayHeader from '../components/DayHeader.vue'
import FilterSheet from '../components/FilterSheet.vue'

const props = defineProps({ md: Object })
const emit = defineEmits(['open'])

const filters = ref({ view: 'today', aircraft: 0, fi: 0, pilot: 0 })
const items = ref([])
const loading = ref(true)
const sheet = ref(false)

async function load() {
  loading.value = true
  try {
    items.value = await api.bookings(filters.value)
  } finally {
    loading.value = false
  }
}
watch(filters, load, { deep: true, immediate: true })

function applyFilters(f) {
  filters.value = f
  sheet.value = false
}

const count = computed(() =>
  (filters.value.aircraft ? 1 : 0) +
  (filters.value.fi ? 1 : 0) +
  (filters.value.pilot ? 1 : 0) +
  (filters.value.view !== 'all' ? 1 : 0)
)

const summary = computed(() => {
  const vmap = { today: 'Heute', tomorrow: 'Morgen', thisweek: 'Diese Woche', all: 'Alle' }
  const parts = []
  if (filters.value.view !== 'all') parts.push(vmap[filters.value.view] || '')
  const a = props.md.aircraft.find((x) => x.id === filters.value.aircraft)
  if (a) parts.push(a.callsign)
  const i = props.md.instructors.find((x) => x.id === filters.value.fi)
  if (i) parts.push(i.name)
  const p = props.md.pilots.find((x) => x.id === filters.value.pilot)
  if (p) parts.push(p.name)
  return parts.length ? parts.join(' · ') : 'Alle Reservierungen'
})

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
  <div class="nav"><div class="large">Alle Reservierungen</div></div>
  <div class="filterbtn-b" @click="sheet = true">
    <span class="fb-ico">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M4 5h16l-6 7v5l-4 2v-7z"/></svg>
    </span>
    <span class="fb-txt">
      <span class="fb-t">Filter</span>
      <span class="fb-s">{{ summary }}</span>
    </span>
    <span v-if="count" class="fb-count">{{ count }}</span>
    <span class="fb-chev">›</span>
  </div>
  <div class="body">
    <div v-if="loading" class="center">Lädt…</div>
    <div v-else-if="!items.length" class="center">Keine Reservierungen</div>
    <template v-else>
      <div v-for="g in grouped" :key="g.date">
        <DayHeader :label="dayLabel(g.date)" :count="g.items.length" />
        <BookingCard v-for="b in g.items" :key="b.id" :b="b" @open="emit('open', $event)" />
      </div>
    </template>
  </div>
  <FilterSheet v-if="sheet" :md="md" :filters="filters" @close="sheet = false" @apply="applyFilters" />
</template>
