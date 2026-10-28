<script setup lang="ts">
import { HilosStatusPage } from '@hilos/vue/status'

import { actions, connection } from '../../bootstrap/connection.js'

defineOptions({ name: 'PublicStatusPage' })
</script>

<template>
  <HilosStatusPage :connection="connection" :actions="actions" />
</template>
