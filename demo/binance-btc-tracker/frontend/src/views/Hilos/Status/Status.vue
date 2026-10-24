<!-- The framework owns the Status Overview and its incident dialogs. -->
<script setup lang="ts">
import { HilosStatusPage } from '@hilos/vue/admin/status'

import { hilosStatusContext } from './hilosStatusContext.js'

defineOptions({ name: 'StatusPage' })
</script>

<template>
  <HilosStatusPage :context="hilosStatusContext" />
</template>
