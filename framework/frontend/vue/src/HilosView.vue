<!-- HilosView — the routed outlet (the `<router-view>` of the SDK). It mirrors
the core navigator's current route and renders the component the project mapped
to that page key, swapping it in place as navigation changes the route. An
unmapped page renders nothing. The router must be provided at the app root
(hilosRouterKey). -->
<script setup lang="ts">
import { computed, inject } from 'vue'
import type { Component } from 'vue'

import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{ pages: Record<string, Component> }>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosView requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

const route = useSignal(router.currentRoute)
const view = computed(() => props.pages[route.value.page])
</script>

<template>
  <component :is="view" v-if="view" />
</template>
