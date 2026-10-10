<script setup lang="ts">
import { HilosDaemonWorkersPage } from '@hilos/vue'
import { hilosDaemonWorkersContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonWorkers' })
</script>

<template>
  <HilosDaemonWorkersPage :context="hilosDaemonWorkersContext" />
</template>
