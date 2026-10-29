<!-- The framework owns the read-only History viewport. -->
<script setup lang="ts">
import { HilosStatusHistoryPage } from '@hilos/vue/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

defineOptions({ name: 'StatusHistoryPage' })
</script>

<template>
  <HilosStatusHistoryPage :context="hilosStatusContext" />
</template>
