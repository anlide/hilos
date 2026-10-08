<script setup lang="ts">
import { HilosPages } from '@hilos/core'

import HilosRootPrimitive from '../../HilosRootPrimitive.vue'
import HilosSharedDetail from '../shared/HilosSharedDetail.vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.STAGED">
    <div data-id="staged-page-only" />
    <HilosSharedDetail />
    <HilosRootPrimitive />
  </HilosAdminPage>
</template>
