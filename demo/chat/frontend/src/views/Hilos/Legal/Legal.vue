<script setup lang="ts">
import { HilosLegalPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'

defineOptions({ name: 'LegalPage' })
</script>

<template>
  <HilosLegalPage :context="hilosLegalContext" />
</template>
