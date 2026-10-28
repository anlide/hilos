<!-- Binance BTC Tracker mounts the framework Tracked table page with its shared context. -->
<script setup lang="ts">
import { HilosChangeLogTableColumnsPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogTableColumnsPage' })
</script>

<template>
  <HilosChangeLogTableColumnsPage :context="hilosChangeLogContext" />
</template>
