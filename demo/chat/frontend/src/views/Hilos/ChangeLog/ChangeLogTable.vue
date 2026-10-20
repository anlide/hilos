<!-- Chat mounts the framework Tracked table page with its shared context. -->
<script setup lang="ts">
import { HilosChangeLogTablePage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogTablePage' })
</script>

<template>
  <HilosChangeLogTablePage :context="hilosChangeLogContext" />
</template>
