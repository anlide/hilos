<!-- HilosView — the routed outlet (the `<router-view>` of the SDK). It mirrors
the core navigator's current route and renders the component the project mapped
to that page key, swapping it in place as navigation changes the route. A page
subscription error (subscription_page_error) takes precedence over the mapped
component: the full-page ErrorPage shows instead. An unmapped page renders
nothing. The router must be provided at the app root (hilosRouterKey). -->
<script setup lang="ts">
import { computed, inject } from 'vue'
import type { Component } from 'vue'

import ErrorPage from './ErrorPage.vue'
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
const pageError = useSignal(router.pageError)
const view = computed(() => props.pages[route.value.page])
</script>

<template>
  <ErrorPage v-if="pageError" :error="pageError" />
  <component :is="view" v-else-if="view" />
</template>
