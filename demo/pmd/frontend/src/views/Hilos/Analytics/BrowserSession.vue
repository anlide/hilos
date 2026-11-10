<script setup lang="ts">
import { HilosAnalyticsBrowserSessionPage } from '@hilos/vue/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'
</script>

<template>
  <HilosAnalyticsBrowserSessionPage :context="hilosAnalyticsContext" />
</template>
