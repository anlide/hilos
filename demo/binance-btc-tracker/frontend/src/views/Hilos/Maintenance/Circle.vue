<!-- The Maintenance Circle tab, bound to the same project context as Overview. -->
<script setup lang="ts">
import { HilosMaintenanceCirclePage } from '@hilos/vue/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

defineOptions({ name: 'MaintenanceCirclePage' })
</script>

<template>
  <HilosMaintenanceCirclePage :context="hilosMaintenanceContext" />
</template>
