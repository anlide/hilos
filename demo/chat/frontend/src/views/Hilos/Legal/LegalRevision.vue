<script setup lang="ts">
import { HilosLegalRevisionPage } from '@hilos/vue/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'
</script>

<template>
  <HilosLegalRevisionPage :context="hilosLegalContext" />
</template>
