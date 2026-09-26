<script setup lang="ts">
import {
  startHilosNotificationPreferences,
  type HilosProfileNotificationsContext,
} from '@hilos/core'
import { onMounted, onUnmounted } from 'vue'
import HilosNotificationPreferences from '../HilosNotificationPreferences.vue'
import HilosPageHeading from '../HilosPageHeading.vue'

const props = defineProps<{ context: HilosProfileNotificationsContext }>()
let stop: (() => void) | null = null
onMounted(() => {
  stop = startHilosNotificationPreferences(props.context)
})
onUnmounted(() => stop?.())
</script>

<template>
  <section data-id="profile-notifications-view">
    <HilosPageHeading />
    <HilosNotificationPreferences :connection="context.connection" />
  </section>
</template>
