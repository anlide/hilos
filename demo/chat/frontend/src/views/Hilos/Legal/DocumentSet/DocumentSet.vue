<script setup lang="ts">
import { HilosLegalDocumentSetPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from '../hilosLegalContext.js'

defineOptions({ name: 'LegalDocumentSetPage' })
</script>

<template>
  <HilosLegalDocumentSetPage :context="hilosLegalContext" />
</template>
