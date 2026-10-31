<script setup lang="ts">
import { HilosLegalDocumentRevisionsPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from '../hilosLegalContext.js'

defineOptions({ name: 'LegalDocumentRevisionsPage' })
</script>

<template>
  <HilosLegalDocumentRevisionsPage :context="hilosLegalContext" />
</template>
