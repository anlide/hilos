<!-- The Hilos maintenance section (HilosPages.MAINTENANCE): a thin project binding
of the framework HilosMaintenancePage to this app's context. The verifier circle
table, its row view-model, and its live online mark are the framework's; the project
supplies only the context (its scope stores + live connection) and registers the
page and the circle table on its backend. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { HilosMaintenancePage } from '@hilos/vue'

import { hilosMaintenanceContext } from './hilosMaintenanceContext'

defineOptions({ name: 'MaintenancePage' })
</script>

<template>
  <HilosMaintenancePage :context="hilosMaintenanceContext" />
</template>
