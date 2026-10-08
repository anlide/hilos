<script setup lang="ts">
import { HilosPages } from '@hilos/core'

import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE">
    <HilosI18nLanguageHeader />
    <div data-id="language-page-only" />
  </HilosAdminPage>
</template>
