<!-- HilosToastHost — the framework toast stack: transient notices in a fixed
bottom-end corner, newest at the bottom, each self-expiring on the store's timer.
It renders the shared core store (hilosToasts), so anything in the SDK or in a
project can report an outcome without threading a store through props — the shell
mounts this once (HilosLayout) and every page is covered.

The host owns no policy: it draws the cards, reports raw measurements (the window
height and each card's occupied height) and reports the holds on the countdown.
How long a notice lives, how much of the corner it may fill and what happens to
the ones that do not fit is the store's business, in one copy for the three SDKs
(core/src/state/toasts.ts).

Markup is the Bootstrap toast component driven declaratively — `.toast.show`
rendered by the store rather than Bootstrap's JS Toast (the SDK ships Bootstrap's
CSS, not its JS; HilosModal does the same). Colored variants use the documented
header-less form: a body plus a close button on a `text-bg-*` surface. Bootstrap
classes only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import type { ComponentPublicInstance } from 'vue'
import { onMounted, onUnmounted, onUpdated } from 'vue'
import type {
  HilosToastSeverity,
  HilosToastStore,
  HilosToastViewer,
} from '@hilos/core'
import { hilosToasts } from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The stack to render; defaults to the application-wide store. */
  store?: HilosToastStore
}>()

const store = props.store ?? hilosToasts
const toasts = useSignal(store.toasts)

// Each severity maps to a Bootstrap color surface; the close button flips to its
// light variant on the dark surfaces so it stays visible.
const SURFACE: Record<HilosToastSeverity, string> = {
  error: 'text-bg-danger',
  success: 'text-bg-success',
  warning: 'text-bg-warning',
  info: 'text-bg-secondary',
}

// Only a failure earns an interrupt; a success, a caveat or a plain notice waits
// its turn rather than cutting into what the screen-reader user is listening to.
const LIVE_REGION: Record<
  HilosToastSeverity,
  { role: string; ariaLive: 'assertive' | 'polite' }
> = {
  error: { role: 'alert', ariaLive: 'assertive' },
  success: { role: 'status', ariaLive: 'polite' },
  warning: { role: 'status', ariaLive: 'polite' },
  info: { role: 'status', ariaLive: 'polite' },
}

// The cursor and keyboard focus are two independent holds on the countdown: the
// host only reports them, the store counts them. A focus move inside the stack —
// tabbing from one close button to the next — is neither an arrival nor a leave,
// and relatedTarget is what tells those apart; without the check the holds would
// never balance out.
function movesWithin(event: FocusEvent): boolean {
  const container = event.currentTarget as HTMLElement

  return container.contains(event.relatedTarget as Node | null)
}

/**
 * How much of the stack one card takes: its own box plus the spacing under it.
 *
 * The store adds these up against a third of the window, so what it is given has
 * to be the room the card occupies, not the room it paints in.
 *
 * @param element The rendered card.
 */
function occupiedHeight(element: HTMLElement): number {
  const spacing = Number.parseFloat(getComputedStyle(element).marginBottom)

  return (
    element.getBoundingClientRect().height +
    (Number.isNaN(spacing) ? 0 : spacing)
  )
}

// Attached for as long as this host is mounted. While no viewer is attached the
// store's countdown does not run at all, which is what keeps a notice that
// arrived before the first frame from burning down behind the splash screen.
let viewer: HilosToastViewer | null = null
const cards = new Map<number, HTMLElement>()

// A tab nobody is looking at is a hold like the cursor and the focus: walk away
// and everything is still there when you come back. It is tracked because
// visibilitychange fires on every switch and the host owes the store exactly one
// hold of each kind.
let tabHeld = false

function onResize(): void {
  viewer?.setViewportHeight(window.innerHeight)
}

function onVisibility(): void {
  if (viewer === null || document.hidden === tabHeld) {
    return
  }
  tabHeld = document.hidden
  if (tabHeld) {
    viewer.hold()

    return
  }
  viewer.release()
}

/**
 * Remember the element of one card, or forget it when the card is gone.
 *
 * @param id The toast's id.
 * @param element The rendered card, or `null` when it leaves the DOM.
 */
function keep(
  id: number,
  element: Element | ComponentPublicInstance | null,
): void {
  if (element === null) {
    cards.delete(id)

    return
  }
  cards.set(id, element as HTMLElement)
}

// Measured after the browser has laid the cards out, and again after every
// render: a card is only really on screen once the store knows how tall it is,
// and every report after the first only updates the number.
function reportHeights(): void {
  if (viewer === null) {
    return
  }
  for (const [id, element] of cards) {
    viewer.reportHeight(id, occupiedHeight(element))
  }
}

onMounted(() => {
  viewer = store.attach()
  viewer.setViewportHeight(window.innerHeight)
  onVisibility()
  window.addEventListener('resize', onResize)
  document.addEventListener('visibilitychange', onVisibility)
  reportHeights()
})

onUpdated(reportHeights)

onUnmounted(() => {
  window.removeEventListener('resize', onResize)
  document.removeEventListener('visibilitychange', onVisibility)
  // detach gives back whatever holds this host was still holding.
  viewer?.detach()
  viewer = null
})

// The host owns at most one hold of each kind and knows whether it is holding it,
// because a toast that closes under the pointer takes the release event with it:
// Chrome and WebKit fire no mouseleave and no focusout for an element that leaves
// the DOM, and they never make it up afterwards. So both holds are given back by
// hand in dismiss(), and the cursor's one is re-taken from mouseover rather than
// mouseenter — mouseover also fires for the toast that slides under a cursor
// standing still, which is exactly what happens to the notice below the one just
// closed.
let cursorHeld = false
let focusHeld = false

function holdOnCursor(): void {
  if (viewer !== null && !cursorHeld) {
    cursorHeld = true
    viewer.hold()
  }
}

function releaseCursorHold(): void {
  if (cursorHeld) {
    cursorHeld = false
    viewer?.release()
  }
}

function releaseFocusHold(): void {
  if (focusHeld) {
    focusHeld = false
    viewer?.release()
  }
}

function holdOnFocus(event: FocusEvent): void {
  if (viewer !== null && !focusHeld && !movesWithin(event)) {
    focusHeld = true
    viewer.hold()
  }
}

function releaseOnBlur(event: FocusEvent): void {
  if (!movesWithin(event)) {
    releaseFocusHold()
  }
}

function dismiss(id: number): void {
  releaseCursorHold()
  releaseFocusHold()
  store.dismiss(id)
}
</script>

<template>
  <div
    class="toast-container position-fixed bottom-0 end-0 p-3"
    data-id="hilos-toasts"
    @mouseover="holdOnCursor"
    @mouseleave="releaseCursorHold"
    @focusin="holdOnFocus"
    @focusout="releaseOnBlur"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      :ref="(element) => keep(toast.id, element)"
      class="toast show align-items-center border-0 d-flex"
      :class="SURFACE[toast.severity]"
      :role="LIVE_REGION[toast.severity].role"
      :aria-live="LIVE_REGION[toast.severity].ariaLive"
      aria-atomic="true"
      :data-id="`hilos-toast-${toast.severity}`"
    >
      <div class="toast-body">{{ toast.message }}</div>
      <button
        type="button"
        class="btn-close btn-close-white me-2 m-auto"
        aria-label="Close"
        data-id="hilos-toast-close"
        @click="dismiss(toast.id)"
      ></button>
    </div>
  </div>
</template>
