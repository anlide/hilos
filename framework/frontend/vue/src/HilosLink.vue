<!-- HilosLink — the in-place navigation link. It renders a real `<a :href>`, so
it keeps every native affordance (open-in-new-tab, copy address, keyboard
focus) and degrades to a hard navigation when no router is provided. Only the
plain primary click is intercepted and handed to the core navigator
(HilosRouter), turning it into a no-refresh page transition that leaves the
WebSocket connection untouched. Class, aria, and data attributes set on the
component fall through to the anchor. -->
<script setup lang="ts">
import { inject } from 'vue'

import { hilosRouterKey } from './hilosRouterKey.js'

const props = defineProps<{ to: string }>()

const router = inject(hilosRouterKey, undefined)

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
  <a :href="to" @click="onClick"><slot /></a>
</template>
