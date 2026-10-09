<script setup lang="ts">
import { HilosI18nLanguagesPage } from '@hilos/vue'
import { hilosI18nLanguageContext } from '../Language/hilosI18nLanguageContext.js'

defineOptions({ name: 'HilosI18nLanguages' })
</script>

<template>
  <HilosI18nLanguagesPage :context="hilosI18nLanguageContext" />
</template>
