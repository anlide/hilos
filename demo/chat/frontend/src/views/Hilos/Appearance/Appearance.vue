<script setup lang="ts">
import { HilosAppearancePage } from '@hilos/vue'
import { hilosAppearanceContext } from './hilosAppearanceContext.js'

defineOptions({ name: 'AppearancePage' })
</script>

<template>
  <HilosAppearancePage :context="hilosAppearanceContext" />
</template>
