<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { HilosPageHeading, HilosProfileDevices, useSignal } from '@hilos/vue'

import { connection } from '../../bootstrap/connection.js'
import { startHilosNotificationPreferences } from '@hilos/core'
import { scopes } from '../../bootstrap/session.js'
import {
  profileDeviceActions,
  profileDevicePushChannel,
  profileDevices,
} from './profileDevicesPage.js'

defineOptions({ name: 'ProfileDevicesPage' })

const devices = useSignal(profileDevices)
const pushChannel = useSignal(profileDevicePushChannel)
let stopPreferences: (() => void) | null = null

onMounted(() => {
  stopPreferences = startHilosNotificationPreferences({ connection, scopes })
})
onUnmounted(() => {
  stopPreferences?.()
})
</script>

<template>
  <section>
    <HilosPageHeading data-id="profile-devices-heading" />
    <HilosProfileDevices
      :devices="devices"
      :actions="profileDeviceActions"
      :connection="connection"
      :push-channel="pushChannel"
    />
  </section>
</template>
