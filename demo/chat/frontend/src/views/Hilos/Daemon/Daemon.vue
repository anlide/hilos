<script setup lang="ts">
import { HilosDaemonPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemon' })
</script>

<template>
  <HilosDaemonPage :context="hilosDaemonContext" />
</template>
