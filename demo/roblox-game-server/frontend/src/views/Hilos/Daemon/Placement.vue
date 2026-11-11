<script setup lang="ts">
import { HilosDaemonPlacementPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonPlacement' })
</script>

<template>
  <HilosDaemonPlacementPage :context="hilosDaemonContext" />
</template>
