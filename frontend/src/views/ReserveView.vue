<script setup>
import { ref } from 'vue'
import BookingForm from '../components/BookingForm.vue'

defineProps({ md: Object, prefill: { type: Object, default: null } })
const emit = defineEmits(['saved'])
const form = ref(null)
</script>

<template>
  <div class="nav">
    <div class="large">Reservieren</div>
  </div>
  <BookingForm ref="form" :md="md" :initial="prefill" @saved="emit('saved')" />
</template>
