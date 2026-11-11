<!-- Mount the framework Change Log page with the project context. -->
<script setup lang="ts">
import { HilosChangeLogNoisyPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogNoisy' })
</script>

<template>
  <HilosChangeLogNoisyPage :context="hilosChangeLogContext" />
</template>
