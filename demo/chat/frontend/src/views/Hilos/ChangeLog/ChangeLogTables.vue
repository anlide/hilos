<!-- Chat mounts the framework Tracked tables page with its shared context. -->
<script setup lang="ts">
import { HilosChangeLogTablesPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogTablesPage' })
</script>

<template>
  <HilosChangeLogTablesPage :context="hilosChangeLogContext" />
</template>
