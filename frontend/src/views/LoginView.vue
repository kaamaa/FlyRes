<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api.js'

const emit = defineEmits(['authed'])
const username = ref('')
const password = ref('')
const err = ref('')
const busy = ref(false)

const clients = ref([])          // [{id, name}]
const client = ref('')           // ausgewaehlter Mandanten-Name
// "Angemeldet bleiben" – Standard an; Wunsch wird gemerkt
const remember = ref(localStorage.getItem('flyres_remember') !== '0')

// Mandantenliste vor dem Login laden. Schlaegt das fehl (oder gibt es nur einen
// Mandanten), bleibt die Auswahl unsichtbar; der Login nutzt dann den Default.
onMounted(async () => {
  try {
    const list = await api.clients()
    clients.value = Array.isArray(list) ? list : []
    const remembered = localStorage.getItem('flyres_client')
    const match = clients.value.find((c) => c.name === remembered)
    client.value = match ? match.name : (clients.value[0]?.name || '')
  } catch (e) {
    clients.value = []
  }
})

async function submit() {
  if (!username.value || !password.value) return
  err.value = ''
  busy.value = true
  try {
    await api.login(username.value, password.value, client.value || undefined, remember.value)
    if (client.value) localStorage.setItem('flyres_client', client.value)
    localStorage.setItem('flyres_remember', remember.value ? '1' : '0')
    emit('authed')
  } catch (e) {
    err.value = e.status === 401 ? 'Benutzername oder Passwort falsch' : 'Anmeldung fehlgeschlagen'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="login">
    <div class="brand"><h1>FlyRes</h1><p class="sub">Flugzeugreservierung</p></div>

    <div v-if="clients.length > 1" class="field">
      <label for="lg-client">Benutzergruppe</label>
      <select id="lg-client" v-model="client" class="clientsel">
        <option v-for="c in clients" :key="c.id" :value="c.name">{{ c.name }}</option>
      </select>
    </div>

    <div class="field">
      <label for="lg-user">Benutzername</label>
      <input id="lg-user" v-model="username" autocapitalize="none" autocorrect="off" autocomplete="username" />
    </div>

    <div class="field">
      <label for="lg-pass">Passwort</label>
      <input id="lg-pass" v-model="password" type="password" autocomplete="current-password" @keyup.enter="submit" />
    </div>

    <label class="remember"><input type="checkbox" v-model="remember" /> Angemeldet bleiben</label>

    <div v-if="err" class="errbox">{{ err }}</div>
    <button class="bigbtn" style="margin-left:0;width:100%;" :disabled="busy" @click="submit">Anmelden</button>
  </div>
</template>
