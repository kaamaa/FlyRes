<script setup>
import { ref } from 'vue'
import BookingForm from '../components/BookingForm.vue'

defineProps({ md: Object })
const emit = defineEmits(['saved'])
const form = ref(null)
</script>

<template>
  <div class="nav">
    <div class="row"><span></span><button class="action bold" @click="form && form.submit()">Speichern</button></div>
    <div class="large">Reservieren</div>
  </div>
  <BookingForm ref="form" :md="md" @saved="emit('saved')" />
</template>
