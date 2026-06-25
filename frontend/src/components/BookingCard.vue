<script setup>
import { badgeClass, barColor } from '../util.js'
defineProps({ b: Object })
defineEmits(['open'])
</script>

<template>
  <div class="card" @click="$emit('open', b.id)">
    <div class="timecol">
      <div class="t1">{{ b.start }}</div>
      <div class="t2">{{ b.end }}</div>
    </div>
    <div class="vbar" :style="{ background: barColor(b) }"></div>
    <div class="info">
      <div class="plane">{{ b.aircraft }}</div>
      <div class="sub">{{ b.pilot }}<template v-if="b.instructor"> · FI {{ b.instructor }}</template></div>
      <span class="badge" :class="badgeClass(b.purpose)">{{ b.purpose }}</span>
    </div>
    <div class="chev">›</div>
  </div>
</template>
