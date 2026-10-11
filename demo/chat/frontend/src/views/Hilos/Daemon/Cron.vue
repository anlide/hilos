<script setup lang="ts">
import { HilosDaemonCronPage } from '@hilos/vue'
import { hilosDaemonCronContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonCron' })
</script>

<template>
  <HilosDaemonCronPage :context="hilosDaemonCronContext" />
</template>
