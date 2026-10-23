<script setup lang="ts">
import { HilosI18nPage } from '@hilos/vue/admin/i18n'
import { scopes } from '../../../bootstrap/session.js'

defineOptions({ name: 'HilosI18n' })
</script>

<template>
  <HilosI18nPage :context="{ scopes }" />
</template>
