<script setup lang="ts">
import { HilosI18nCountryPage } from '@hilos/vue'
import { hilosI18nCountryContext } from './hilosI18nCountryContext.js'

defineOptions({ name: 'HilosI18nCountry' })
</script>

<template>
  <HilosI18nCountryPage :context="hilosI18nCountryContext" />
</template>
