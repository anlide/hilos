<script setup lang="ts">
import { HilosI18nLanguagePage } from '@hilos/vue'
import { hilosI18nLanguageContext } from './hilosI18nLanguageContext.js'

defineOptions({ name: 'HilosI18nLanguage' })
</script>

<template>
  <HilosI18nLanguagePage :context="hilosI18nLanguageContext" />
</template>
