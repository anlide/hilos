<script setup lang="ts">
import { HilosAnalyticsPage } from '@hilos/vue/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'

defineOptions({ name: 'HilosAnalytics' })
</script>

<template>
  <HilosAnalyticsPage :context="hilosAnalyticsContext" />
</template>
