<script setup lang="ts">
import { HilosLegalPage } from '@hilos/vue'
import { hilosLegalContext } from './hilosLegalContext.js'

defineOptions({ name: 'LegalPage' })
</script>

<template>
  <HilosLegalPage :context="hilosLegalContext" />
</template>
