<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { HilosProfileDevices, useSignal } from '@hilos/vue'

import { connection } from '../../bootstrap/connection.js'
import { bindNotificationPreferences } from '../../profile/notificationPreferences.js'
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
  stopPreferences = bindNotificationPreferences()
})
onUnmounted(() => {
  stopPreferences?.()
})
</script>

<template>
  <section>
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0" data-id="profile-devices-heading">Devices</h1>
      <p class="mb-0 text-body-secondary">
        Where push notifications are delivered.
      </p>
    </div>
    <HilosProfileDevices
      :devices="devices"
      :actions="profileDeviceActions"
      :connection="connection"
      :push-channel="pushChannel"
    />
  </section>
</template>
