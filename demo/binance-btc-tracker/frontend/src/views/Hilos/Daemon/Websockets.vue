<script setup lang="ts">
import { HilosDaemonWebsocketsPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonWebsockets' })
</script>

<template>
  <HilosDaemonWebsocketsPage :context="hilosDaemonContext" />
</template>
