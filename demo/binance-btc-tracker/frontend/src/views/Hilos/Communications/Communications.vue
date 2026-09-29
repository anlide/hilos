<!-- The Hilos communications hub page (HilosPages.COMMUNICATIONS): a thin project
binding of the framework HilosCommunicationsPage to this app's context. The
channels table, the row view-model, and the enablement round-trip are the
framework's; the project supplies only the context (its scope stores + live
connection + action lifecycle) and registers the channel registry on its backend.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosCommunicationsPage } from '@hilos/vue'

import { hilosCommunicationsContext } from './hilosCommunicationsContext'

defineOptions({ name: 'CommunicationsPage' })
</script>

<template>
  <HilosCommunicationsPage :context="hilosCommunicationsContext" />
</template>
