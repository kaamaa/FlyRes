<script setup>
import { ref } from 'vue'
import BookingForm from './BookingForm.vue'

defineProps({ md: Object, bookingId: Number, initial: Object })
const emit = defineEmits(['close', 'saved'])
const form = ref(null)
</script>

<template>
  <div class="approot" style="position:absolute;inset:0;z-index:26;background:var(--ios-bg);">
    <div class="nav">
      <div class="row">
        <button class="action" @click="emit('close')">Abbrechen</button>
        <span></span>
      </div>
      <div class="large">Bearbeiten</div>
    </div>
    <BookingForm ref="form" :md="md" :initial="initial" :booking-id="bookingId" @saved="emit('saved')" />
  </div>
</template>
