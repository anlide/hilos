<script setup lang="ts">
import { HilosAnalyticsPersonPage } from '@hilos/vue'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'

defineOptions({ name: 'HilosAnalyticsPerson' })
</script>

<template>
  <HilosAnalyticsPersonPage :context="hilosAnalyticsContext" />
</template>
