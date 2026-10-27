<script setup lang="ts">
import { HilosAnalyticsBrowserSessionsPage } from '@hilos/vue/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'

defineOptions({ name: 'HilosAnalyticsBrowserSessions' })
</script>

<template>
  <HilosAnalyticsBrowserSessionsPage :context="hilosAnalyticsContext" />
</template>
