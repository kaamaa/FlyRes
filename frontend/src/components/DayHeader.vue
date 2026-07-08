<script setup>
// Variante B: kräftiger Tagestrenner mit Linie und Anzahl-Badge.
defineProps({ label: String, count: Number })
</script>

<template>
  <div class="dayhdr-b">
    <span class="d">{{ label }}</span>
    <span class="line"></span>
    <span class="cnt">{{ count }}</span>
  </div>
</template>
