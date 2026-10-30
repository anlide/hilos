<script setup lang="ts">
import { HilosLegalDocumentsPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from '../hilosLegalContext.js'

defineOptions({ name: 'LegalDocumentsPage' })
</script>

<template>
  <HilosLegalDocumentsPage :context="hilosLegalContext" />
</template>
