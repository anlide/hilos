<script setup lang="ts">
import { HilosAnalyticsPeoplePage } from '@hilos/vue/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'

defineOptions({ name: 'HilosAnalyticsPeople' })
</script>

<template>
  <HilosAnalyticsPeoplePage :context="hilosAnalyticsContext" />
</template>
