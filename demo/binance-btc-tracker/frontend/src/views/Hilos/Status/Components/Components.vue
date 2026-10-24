<!-- The framework owns the read-only Components viewport. -->
<script setup lang="ts">
import { HilosStatusComponentsPage } from '@hilos/vue/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

defineOptions({ name: 'StatusComponentsPage' })
</script>

<template>
  <HilosStatusComponentsPage :context="hilosStatusContext" />
</template>
