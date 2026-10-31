<script setup lang="ts">
import { HilosLegalDocumentDeviationsPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from '../hilosLegalContext.js'

defineOptions({ name: 'LegalDocumentDeviationsPage' })
</script>

<template>
  <HilosLegalDocumentDeviationsPage :context="hilosLegalContext" />
</template>
