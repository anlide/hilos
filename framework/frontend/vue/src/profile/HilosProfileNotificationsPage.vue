<script setup lang="ts">
import {
  hilosNotificationAddressSection,
  startHilosNotificationPreferences,
  type HilosProfileNotificationsContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted } from 'vue'
import HilosNotificationPreferences from '../HilosNotificationPreferences.vue'
import HilosPageHeading from '../HilosPageHeading.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'
import { useSignal } from '../useSignal.js'

const props = defineProps<{ context: HilosProfileNotificationsContext }>()
const router = inject(hilosRouterKey, null)
const addressSection = useSignal(
  hilosNotificationAddressSection(props.context.scopes),
)
const addressTo = computed(() =>
  addressSection.value
    ? router?.resolvePath(addressSection.value.page)
    : undefined,
)
let stop: (() => void) | null = null
onMounted(() => {
  stop = startHilosNotificationPreferences(props.context)
})
onUnmounted(() => stop?.())
</script>

<template>
  <section data-id="profile-notifications-view">
    <HilosPageHeading />
    <HilosNotificationPreferences
      :connection="context.connection"
      :address-section="addressSection"
      :address-to="addressTo"
    />
  </section>
</template>
