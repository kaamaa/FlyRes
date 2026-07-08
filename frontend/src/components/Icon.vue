<script setup>
defineProps({ name: String })
</script>

<template>
  <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <template v-if="name === 'plane'"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></template>
    <template v-else-if="name === 'user'"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></template>
    <template v-else-if="name === 'clock'"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></template>
    <template v-else-if="name === 'blocks'"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></template>
    <template v-else-if="name === 'chart'"><path d="M5 20V13M12 20V5M19 20v-9"/></template>
    <template v-else-if="name === 'pin'"><path d="M12 22s7-7 7-12a7 7 0 1 0-14 0c0 5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/></template>
    <template v-else-if="name === 'note'"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/></template>
    <template v-else-if="name === 'training'"><path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1 3 2 6 2s6-1 6-2v-5"/></template>
    <template v-else-if="name === 'charter'"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M8 8V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></template>
    <template v-else-if="name === 'wrench'"><path d="M15 7a4 4 0 0 1-5.2 3.8L5 15.6 8.4 19l4.8-4.8A4 4 0 0 0 17 9l-2.3 2.3-1.9-1.9z"/></template>
    <template v-else><path d="M2 12l20-8-8 20-2-8z"/></template>
  </svg>
</template>
