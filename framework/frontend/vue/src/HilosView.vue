<!-- HilosView — the routed outlet (the `<router-view>` of the SDK). It mirrors
the core navigator's current route and renders the component the project mapped
to that page key, swapping it in place as navigation changes the route. A page
subscription error (subscription_page_error) takes precedence over the mapped
component: the full-page ErrorPage shows instead. An unmapped page renders
nothing. The router must be provided at the app root (hilosRouterKey).

A page is held back until its subscription answers (router.pageLoading): showing
it earlier means showing a page the server may be about to deny, and taking it
away again one round trip later. The `hilos-page-state` marker names the outcome
— loading, error or ready — so the state is readable from the DOM rather than
guessed from whichever element happened to render first.

It also hosts the auth gate (HIL-165): when the project registers an
`authSurface`, an anonymous 401 mounts that surface IN PLACE of ErrorPage, and
the `authGate`'s modal shows the same surface over the live page for a gated
action. Both dismiss and resume through the core gate — no navigation. Omit the
pair and behavior is unchanged: a 401 renders ErrorPage like any status. -->
<script setup lang="ts">
import { computed, inject } from 'vue'
import type { Component } from 'vue'
import { createSignal } from '@hilos/core'
import type { AuthGate } from '@hilos/core'

import ErrorPage from './ErrorPage.vue'
import HilosModal from './HilosModal.vue'
import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  pages: Record<string, Component>
  authSurface?: Component
  authGate?: AuthGate
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosView requires a provided router: app.provide(hilosRouterKey, router).',
  )
}

/** A stable closed signal for the modal state when no auth gate is registered. */
const modalClosed = createSignal(false)

const route = useSignal(router.currentRoute)
const pageError = useSignal(router.pageError)
const pageLoading = useSignal(router.pageLoading)
const modalOpen = useSignal(props.authGate?.modalOpen ?? modalClosed)
const view = computed(() => props.pages[route.value.page])

// The one place the three outcomes of a navigation are named. The marker element
// carries it so a test waits for the page to be settled instead of polling for
// an element that renders before the answer and disappears when it lands.
const pageState = computed(() =>
  pageError.value ? 'error' : pageLoading.value ? 'loading' : 'ready',
)
const showAuthInPlace = computed(
  () =>
    !!pageError.value &&
    pageError.value.httpCode === 401 &&
    !!props.authSurface,
)

// The modal only ever closes from within (Esc/backdrop/close button); route that
// back through the gate so its state stays the single source of truth.
function onModalToggle(open: boolean): void {
  if (!open) {
    props.authGate?.dismiss()
  }
}
</script>

<template>
  <div data-id="hilos-page-state" :data-state="pageState" hidden />
  <component :is="props.authSurface" v-if="showAuthInPlace" />
  <ErrorPage v-else-if="pageError" :error="pageError" />
  <component :is="view" v-else-if="view && !pageLoading" />
  <HilosModal
    v-if="props.authSurface && props.authGate"
    :model-value="modalOpen"
    title="Sign in"
    @update:model-value="onModalToggle"
  >
    <component :is="props.authSurface" />
    <template #actions />
  </HilosModal>
</template>
