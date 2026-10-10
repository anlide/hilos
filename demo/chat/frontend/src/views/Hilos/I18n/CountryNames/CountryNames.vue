<script setup lang="ts">
import { HilosI18nCountryNamesPage } from '@hilos/vue'
import { hilosI18nCountryContext } from '../Country/hilosI18nCountryContext.js'

defineOptions({ name: 'HilosI18nCountryNames' })
</script>

<template>
  <HilosI18nCountryNamesPage :context="hilosI18nCountryContext" />
</template>
