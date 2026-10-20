<script setup lang="ts">
import { HilosPages } from '@hilos/core'

import HilosRootPrimitive from '../../HilosRootPrimitive.vue'
import HilosSharedDetail from '../shared/HilosSharedDetail.vue'
const detail = () =>
  import('@hilos/vue/admin/billing').then((m) => m.LazyDetail)
</script>

<template>
  <HilosAdminPage :page="HilosPages.STAGED">
    <div data-id="staged-page-only" />
    <HilosSharedDetail />
    <HilosRootPrimitive />
    <component :is="detail" />
  </HilosAdminPage>
</template>
