<!-- Binance BTC Tracker mounts the framework Tracked tables page with its shared context. -->
<script setup lang="ts">
import { HilosChangeLogTablesPage } from '@hilos/vue'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogTablesPage' })
</script>

<template>
  <HilosChangeLogTablesPage :context="hilosChangeLogContext" />
</template>
