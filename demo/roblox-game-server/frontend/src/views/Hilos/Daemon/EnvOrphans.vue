<script setup lang="ts">
import { HilosDaemonEnvOrphansPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonEnvContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonEnvOrphans' })
</script>

<template>
  <HilosDaemonEnvOrphansPage :context="hilosDaemonEnvContext" />
</template>
