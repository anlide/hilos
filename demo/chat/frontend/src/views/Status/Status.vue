<script setup lang="ts">
import { HilosStatusPage } from '@hilos/vue/status'

import { connection } from '../../bootstrap/connection.js'

defineOptions({ name: 'PublicStatusPage' })
</script>

<template>
  <HilosStatusPage :connection="connection" />
</template>
