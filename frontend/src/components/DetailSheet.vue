<script setup>
import { ref, watch } from 'vue'
import { api } from '../api.js'
import { badgeClass } from '../util.js'

const props = defineProps({ id: Number })
const emit = defineEmits(['close', 'deleted', 'edit'])

const d = ref(null)
const loading = ref(true)
const busy = ref(false)

async function load() {
  loading.value = true
  d.value = null
  try {
    d.value = await api.booking(props.id)
  } finally {
    loading.value = false
  }
}
watch(() => props.id, load, { immediate: true })

async function del() {
  if (!confirm('Reservierung wirklich löschen?')) return
  busy.value = true
  try {
    await api.remove(props.id)
    emit('deleted')
  } catch (e) {
    alert('Löschen fehlgeschlagen: ' + (e.body?.error || e.message))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="approot" style="position:absolute;inset:0;z-index:25;background:var(--ios-bg);">
    <div class="nav">
      <div class="row">
        <button class="action" @click="emit('close')">‹ Zurück</button>
        <button v-if="d && (d.canEditDate ?? d.canEdit)" class="action bold" @click="emit('edit', d)">Bearbeiten</button>
        <span v-else></span>
      </div>
    </div>
    <div class="body">
      <div v-if="loading" class="center">Lädt…</div>
      <template v-else-if="d">
        <div class="dhero" style="background:#0a84ff;">
          <div class="dd">{{ d.start }}</div>
          <div class="dpl">{{ d.aircraft }}</div>
        </div>
        <div class="formgroup" style="margin-top:14px;">
          <div class="drow"><span class="k">Zweck</span><span class="v"><span class="badge" :class="badgeClass(d.purpose)">{{ d.purpose }}</span></span></div>
          <div class="drow" v-if="d.fiOpen"><span class="k">Fluglehrer</span><span class="v" style="color:#c9781a;font-weight:700;">⚠ noch offen – wir unterstützen dich bei der Suche</span></div>
          <div class="drow" v-else-if="d.instructor"><span class="k">Fluglehrer</span><span class="v">{{ d.instructor }}</span></div>
          <div class="drow"><span class="k">Flugplatz</span><span class="v">{{ d.airfield }}</span></div>
          <div class="drow"><span class="k">Ende</span><span class="v">{{ d.end }}</span></div>
          <div class="drow"><span class="k">Pilot</span><span class="v">{{ d.reservedFor }}</span></div>
          <div class="drow" v-if="d.phone && d.phone.mobile"><span class="k">Telefon</span><span class="v">{{ d.phone.mobile }}</span></div>
        </div>
        <div v-if="d.fiOpen" class="fiopen-cta">
          <b>Für diesen Flug fehlt noch ein Fluglehrer.</b>
          <p>„Fluglehrer offen" ist nur ein Platzhalter. Such dir einen passenden Fluglehrer und trag ihn hier ein, damit dein Flug stattfinden kann. Wir helfen dir dabei – bei Fragen ist die Flugschule für dich da.</p>
          <button v-if="d.canEditDate ?? d.canEdit" class="btn" @click="emit('edit', d)">Fluglehrer eintragen</button>
        </div>
        <template v-if="d.description">
          <div class="ftitle">Beschreibung</div>
          <div class="formgroup"><div style="padding:14px 16px;font-size:15px;">{{ d.description }}</div></div>
        </template>
        <button v-if="d.canEdit" class="bigbtn danger" :disabled="busy" @click="del">Reservierung löschen</button>
      </template>
      <div v-else class="center">Nicht gefunden</div>
    </div>
  </div>
</template>
