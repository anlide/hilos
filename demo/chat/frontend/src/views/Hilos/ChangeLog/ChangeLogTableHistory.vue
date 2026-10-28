<!-- Chat mounts the framework Tracked table page with its shared context. -->
<script setup lang="ts">
import { HilosChangeLogTableHistoryPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogTableHistoryPage' })
</script>

<template>
  <HilosChangeLogTableHistoryPage :context="hilosChangeLogContext" />
</template>
