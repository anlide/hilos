<!-- The Binance BTC Tracker Change Log page: the framework draws the overview and feed from
the project's connection and page scope. Bootstrap classes only. -->
<script setup lang="ts">
import { HilosChangeLogPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogPage' })
</script>

<template>
  <HilosChangeLogPage :context="hilosChangeLogContext" />
</template>
