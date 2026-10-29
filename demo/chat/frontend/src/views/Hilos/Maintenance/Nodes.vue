<!-- The Maintenance Nodes tab, bound to the section's shared project context. -->
<script setup lang="ts">
import { HilosMaintenanceNodesPage } from '@hilos/vue/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

defineOptions({ name: 'MaintenanceNodesPage' })
</script>

<template>
  <HilosMaintenanceNodesPage :context="hilosMaintenanceContext" />
</template>
