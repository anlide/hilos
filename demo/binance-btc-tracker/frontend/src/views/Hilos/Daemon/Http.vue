<script setup lang="ts">
import { HilosDaemonHttpServerPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonHttpContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonHttp' })
</script>

<template>
  <HilosDaemonHttpServerPage :context="hilosDaemonHttpContext" />
</template>
