<!-- The catalog identity carried by the current page's single subscription. -->
<script setup lang="ts">
import { hilosCrumbLinks } from '@hilos/core'
import { computed, inject, useId } from 'vue'

import HilosBreadcrumb from './HilosBreadcrumb.vue'
import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

withDefaults(defineProps<{ dataId?: string }>(), { dataId: 'hilos-page-title' })
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosPageHeading requires a provided Hilos router.')
const headingId = useId()
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const crumbs = computed(() =>
  hilosCrumbLinks(
    identity.value?.breadcrumb ?? [],
    route.value.params,
    router.resolvePath,
  ),
)
</script>

<template>
  <template v-if="identity">
    <HilosBreadcrumb v-if="crumbs.length > 1" :crumbs="crumbs" />
    <h1 :id="headingId" class="h4 mb-1" :data-id="dataId">
      {{ identity.label }}
    </h1>
    <p v-if="identity.lead" class="text-body-secondary">{{ identity.lead }}</p>
  </template>
  <div v-else class="placeholder-glow mb-3" :data-id="`${dataId}-skeleton`">
    <span class="placeholder col-3 d-block mb-2 rounded"></span>
    <span class="placeholder col-6 d-block rounded"></span>
  </div>
</template>
