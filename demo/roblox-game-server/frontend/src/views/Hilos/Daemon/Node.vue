<script setup lang="ts">
import { HilosDaemonNodePage } from '@hilos/vue/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonNode' })
</script>

<template>
  <HilosDaemonNodePage :context="hilosDaemonContext" />
</template>
