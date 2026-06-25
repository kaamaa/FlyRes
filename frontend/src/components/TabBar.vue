<script setup>
defineProps({ view: String })
const emit = defineEmits(['change'])
</script>

<template>
  <div class="tabbar">
    <button class="tab" :class="{ on: view === 'neu' }" @click="emit('change', 'neu')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>
      <span>Reservieren</span>
    </button>
    <button class="tab" :class="{ on: view === 'meine' }" @click="emit('change', 'meine')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7h14M5 12h14M5 17h9"/></svg>
      <span>Meine Flüge</span>
    </button>
    <button class="tab" :class="{ on: view === 'alle' }" @click="emit('change', 'alle')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>
      <span>Alle</span>
    </button>
  </div>
</template>
