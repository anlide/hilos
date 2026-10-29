<!-- The framework owns the Works viewport and its mutation windows. -->
<script setup lang="ts">
import { HilosStatusWorksPage } from '@hilos/vue/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

defineOptions({ name: 'StatusWorksPage' })
</script>

<template>
  <HilosStatusWorksPage :context="hilosStatusContext" />
</template>
