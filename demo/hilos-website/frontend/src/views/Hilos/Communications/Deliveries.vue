<!-- The Hilos delivery-logs page (HilosPages.COMMUNICATIONS_DELIVERIES): a thin
project binding of the framework HilosCommunicationsDeliveriesPage to this app's
context. The journal table, the row view-model, the filters, and the retry
round-trip are the framework's; the project supplies only the context (its
connection / scope stores / action lifecycle) and registers the deliveries table
on its backend. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosCommunicationsDeliveriesPage } from '@hilos/vue/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

defineOptions({ name: 'CommunicationsDeliveriesPage' })
</script>

<template>
  <HilosCommunicationsDeliveriesPage :context="hilosCommunicationsContext" />
</template>
