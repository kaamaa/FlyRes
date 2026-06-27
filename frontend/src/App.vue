<script setup>
import { ref, onMounted } from 'vue'
import { api, onUnauthorized } from './api.js'
import TabBar from './components/TabBar.vue'
import DetailSheet from './components/DetailSheet.vue'
import EditSheet from './components/EditSheet.vue'
import LoginView from './views/LoginView.vue'
import MyFlightsView from './views/MyFlightsView.vue'
import AllView from './views/AllView.vue'
import ReserveView from './views/ReserveView.vue'
import FleetView from './views/FleetView.vue'

const user = ref(null)
const ready = ref(false)
const view = ref('meine')
const detailId = ref(null)
const editTarget = ref(null) // { id, edit } zum Bearbeiten
const prefill = ref(null)    // { aircraftId, date } -> Reservieren vorbefüllen (aus Übersicht)
const bump = ref(0) // erzwingt Neuladen der Listen nach Aenderungen
const toast = ref('')
const md = ref({ aircraft: [], instructors: [], pilots: [], purposes: [], airfields: [] })

async function loadMaster() {
  const [aircraft, instructors, pilots, purposes, airfields] = await Promise.all([
    api.aircraft(), api.instructors(), api.pilots(), api.purposes(), api.airfields(),
  ])
  md.value = { aircraft, instructors, pilots, purposes, airfields }
}

async function loadMe() {
  try {
    user.value = await api.me()
    await loadMaster()
  } catch {
    user.value = null
  } finally {
    ready.value = true
  }
}

onUnauthorized(() => { user.value = null })
onMounted(loadMe)

function openDetail(id) { detailId.value = id }
function setView(v) { prefill.value = null; view.value = v } // Tab-Wechsel: evtl. Vorbefüllung verwerfen
function onReserve(p) { prefill.value = p; view.value = 'neu' } // aus Übersicht: Flugzeug+Datum vorbefüllen
function showToast(m) { toast.value = m; setTimeout(() => (toast.value = ''), 1800) }
function onDeleted() { detailId.value = null; showToast('Reservierung gelöscht'); bump.value++ }
function onSaved() { prefill.value = null; view.value = 'meine'; showToast('Reservierung gespeichert'); bump.value++ }
function openEdit(d) { editTarget.value = { id: d.id, edit: d.edit } }
function onEdited() { editTarget.value = null; detailId.value = null; showToast('Änderungen gespeichert'); bump.value++ }

async function logout() {
  try { await api.logout() } catch { /* Session evtl. schon weg – egal */ }
  user.value = null
  view.value = 'meine'
  detailId.value = null
  editTarget.value = null
}
</script>

<template>
  <div class="approot">
    <div v-if="!ready" class="center" style="height:100%;">Lädt…</div>

    <LoginView v-else-if="!user" @authed="loadMe" />

    <template v-else>
      <MyFlightsView v-if="view === 'meine'" :key="'m' + bump" @open="openDetail" @reserve="setView('neu')" @logout="logout" />
      <AllView v-else-if="view === 'alle'" :key="'a' + bump" :md="md" @open="openDetail" />
      <ReserveView v-else-if="view === 'neu'" :key="'r' + bump" :md="md" :prefill="prefill" @saved="onSaved" />
      <FleetView v-else-if="view === 'flotte'" :key="'f' + bump" @open="openDetail" @reserve="onReserve" />

      <TabBar :view="view" @change="setView" />
      <DetailSheet v-if="detailId" :id="detailId" @close="detailId = null" @deleted="onDeleted" @edit="openEdit" />
      <EditSheet v-if="editTarget" :md="md" :booking-id="editTarget.id" :initial="editTarget.edit"
                 @close="editTarget = null" @saved="onEdited" />
      <div v-if="toast" class="toast">{{ toast }}</div>
    </template>
  </div>
</template>
