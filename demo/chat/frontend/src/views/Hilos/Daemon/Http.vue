<script setup lang="ts">
import { HilosDaemonHttpServerPage } from '@hilos/vue'
import { hilosDaemonHttpContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonHttp' })
</script>

<template>
  <HilosDaemonHttpServerPage :context="hilosDaemonHttpContext" />
</template>
