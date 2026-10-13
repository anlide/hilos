<script setup lang="ts">
import { HilosDaemonEnvPage } from '@hilos/vue'
import { hilosDaemonEnvContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonEnv' })
</script>

<template>
  <HilosDaemonEnvPage :context="hilosDaemonEnvContext" />
</template>
