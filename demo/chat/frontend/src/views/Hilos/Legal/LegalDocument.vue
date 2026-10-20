<script setup lang="ts">
import { HilosLegalDocumentPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'
</script>

<template>
  <HilosLegalDocumentPage :context="hilosLegalContext" />
</template>
