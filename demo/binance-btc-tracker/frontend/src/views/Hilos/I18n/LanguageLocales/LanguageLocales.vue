<script setup lang="ts">
import { HilosI18nLanguageLocalesPage } from '@hilos/vue'
import { hilosI18nLanguageContext } from '../Language/hilosI18nLanguageContext.js'

defineOptions({ name: 'HilosI18nLanguageLocales' })
</script>

<template>
  <HilosI18nLanguageLocalesPage :context="hilosI18nLanguageContext" />
</template>
