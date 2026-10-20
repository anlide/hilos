<script setup lang="ts">
import { HilosAppearancePage } from '@hilos/vue/admin/appearance'
import { hilosAppearanceContext } from './hilosAppearanceContext.js'

defineOptions({ name: 'AppearancePage' })
</script>

<template>
  <HilosAppearancePage :context="hilosAppearanceContext" />
</template>
