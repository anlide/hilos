<script setup lang="ts">
import { HilosI18nCountriesPage } from '@hilos/vue/admin/i18n'
import { hilosI18nCountryContext } from '../Country/hilosI18nCountryContext.js'

defineOptions({ name: 'HilosI18nCountries' })
</script>

<template>
  <HilosI18nCountriesPage :context="hilosI18nCountryContext" />
</template>
