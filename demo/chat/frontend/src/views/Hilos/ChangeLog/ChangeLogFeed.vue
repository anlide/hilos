<!-- Mount the framework Change Log page with the project context. -->
<script setup lang="ts">
import { HilosChangeLogFeedPage } from '@hilos/vue/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

defineOptions({ name: 'ChangeLogFeed' })
</script>

<template>
  <HilosChangeLogFeedPage :context="hilosChangeLogContext" />
</template>
