<!-- HilosLink — the in-place navigation link. It renders a real `<a :href>`, so
it keeps every native affordance (open-in-new-tab, copy address, keyboard
focus) and degrades to a hard navigation when no router is provided. Only the
plain primary click is intercepted and handed to the core navigator
(HilosRouter), turning it into a no-refresh page transition that leaves the
WebSocket connection untouched. It marks itself aria-current="page" when it
points at the active route. Class, aria, and data attributes set on the
component fall through to the anchor. -->
<script setup lang="ts">
import { computed, inject } from 'vue'

import { hilosRouterKey } from './hilosRouterKey.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{ to: string }>()

const router = inject(hilosRouterKey, undefined)

// Mark the link aria-current="page" when it targets the active route, so a
// screen reader announces the current page in the navigation. Without a router
// (the hard-link fallback) there is no current path to compare against.
const currentPath = router ? useSignal(router.currentPath) : undefined
const ariaCurrent = computed(() =>
  currentPath?.value === props.to ? 'page' : undefined,
)

function onClick(event: MouseEvent): void {
  // Leave modified clicks and non-primary buttons to the browser (new tab,
  // new window); without a router the anchor stays a plain hard link.
  if (
    !router ||
    event.defaultPrevented ||
    event.button !== 0 ||
    event.metaKey ||
    event.ctrlKey ||
    event.shiftKey ||
    event.altKey
  ) {
    return
  }
  event.preventDefault()
  router.navigate(props.to)
}
</script>

<template>
  <a :href="to" :aria-current="ariaCurrent" @click="onClick"><slot /></a>
</template>
