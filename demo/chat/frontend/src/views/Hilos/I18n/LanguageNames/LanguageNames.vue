<script setup lang="ts">
import { HilosI18nLanguageNamesPage } from '@hilos/vue'
import { hilosI18nLanguageContext } from '../Language/hilosI18nLanguageContext.js'

defineOptions({ name: 'HilosI18nLanguageNames' })
</script>

<template>
  <HilosI18nLanguageNamesPage :context="hilosI18nLanguageContext" />
</template>
