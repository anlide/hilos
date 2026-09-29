<!-- The Hilos by-key page (HilosPages.LOGS_KEYS): a thin project binding of the
framework HilosLogsKeysPage to this app's context. The stream table, the row
view-model, the filters, the header and the four empty states are the framework's;
the project supplies only the context (the same connection and scope stores the
other Hilos pages use) and registers the hilosLogKeys table on its backend.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosLogsKeysPage } from '@hilos/vue'

import { hilosLogKeysContext } from './hilosLogsContext'

defineOptions({ name: 'LogsKeysPage' })
</script>

<template>
  <HilosLogsKeysPage :context="hilosLogKeysContext" />
</template>
