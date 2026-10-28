<!-- The Maintenance Words tab, bound to the same project context as Overview. -->
<script setup lang="ts">
import { HilosMaintenanceWordsPage } from '@hilos/vue/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

defineOptions({ name: 'MaintenanceWordsPage' })
</script>

<template>
  <HilosMaintenanceWordsPage :context="hilosMaintenanceContext" />
</template>
