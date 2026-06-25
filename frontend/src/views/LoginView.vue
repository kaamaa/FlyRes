<script setup>
import { ref } from 'vue'
import { api } from '../api.js'

const emit = defineEmits(['authed'])
const username = ref('')
const password = ref('')
const err = ref('')
const busy = ref(false)

async function submit() {
  if (!username.value || !password.value) return
  err.value = ''
  busy.value = true
  try {
    await api.login(username.value, password.value)
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
    <h1>FlyRes</h1>
    <input v-model="username" placeholder="Benutzername" autocapitalize="none" autocorrect="off" autocomplete="username" />
    <input v-model="password" type="password" placeholder="Passwort" autocomplete="current-password" @keyup.enter="submit" />
    <div v-if="err" class="errbox">{{ err }}</div>
    <button class="bigbtn" style="margin-left:0;width:100%;" :disabled="busy" @click="submit">Anmelden</button>
  </div>
</template>
