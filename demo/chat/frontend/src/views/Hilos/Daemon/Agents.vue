<script setup lang="ts">
import { HilosDaemonAgentsPage } from '@hilos/vue/admin/daemon'
import { hilosDaemonAgentsContext } from './hilosDaemonContext.js'

defineOptions({ name: 'HilosDaemonAgents' })
</script>

<template>
  <HilosDaemonAgentsPage :context="hilosDaemonAgentsContext" />
</template>
