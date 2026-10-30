<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import {
  HilosProfileHead,
  HilosProfileDevices,
  useSignal,
} from '@hilos/vue/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { startHilosNotificationPreferences } from '@hilos/core/base'
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

const context = { connection, scopes, actions }
</script>

<template>
  <section>
    <HilosProfileHead :context="context" />
    <HilosProfileDevices
      :devices="devices"
      :actions="profileDeviceActions"
      :connection="connection"
      :push-channel="pushChannel"
    />
  </section>
</template>
