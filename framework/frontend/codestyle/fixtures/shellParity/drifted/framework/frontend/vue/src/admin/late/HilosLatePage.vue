<script setup lang="ts">
import { HilosPages } from '@hilos/core'

import HilosSharedDetail from '../shared/HilosSharedDetail.vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.LATE">
    <div data-id="late-page-only" />
    <HilosSharedDetail />
  </HilosAdminPage>
</template>
