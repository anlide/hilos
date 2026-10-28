<!-- The framework owns the Subscribers viewport and removal window. -->
<script setup lang="ts">
import { HilosStatusSubscribersPage } from '@hilos/vue/admin/status'

import { hilosStatusContext } from '../hilosStatusContext.js'

defineOptions({ name: 'StatusSubscribersPage' })
</script>

<template>
  <HilosStatusSubscribersPage :context="hilosStatusContext" />
</template>
