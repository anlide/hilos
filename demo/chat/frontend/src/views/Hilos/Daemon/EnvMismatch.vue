<script setup lang="ts">
import { HilosDaemonEnvMismatchPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonEnvMismatchContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonEnvMismatch' })
</script>

<template>
  <HilosDaemonEnvMismatchPage :context="hilosDaemonEnvMismatchContext" />
</template>
