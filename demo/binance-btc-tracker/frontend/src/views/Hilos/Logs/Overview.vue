<!-- The Hilos logs page (HilosPages.LOGS): a thin project binding of the framework
HilosLogsPage to this app's context. The tiles, the takeout banner, the per-node
table and the two empty states are the framework's; the project supplies only the
context, and its backend registers nothing further — the overview page and its agent
are already there and it owns no table. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosLogsPage } from '@hilos/vue'

import { hilosLogsOverviewContext } from './hilosLogsContext'

defineOptions({ name: 'LogsOverviewPage' })
</script>

<template>
  <HilosLogsPage :context="hilosLogsOverviewContext" />
</template>
