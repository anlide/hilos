<!-- Chat mounts the framework Tracked table page with its shared context. -->
<script setup lang="ts">
import { HilosChangeLogTableTriggersPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogTableTriggersPage' })
</script>

<template>
  <HilosChangeLogTableTriggersPage :context="hilosChangeLogContext" />
</template>
