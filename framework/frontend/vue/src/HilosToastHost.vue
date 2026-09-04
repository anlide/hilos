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
CSS, not its JS; HilosModal does the same). The card keeps the stock surface —
the width, the translucent body background, the border, the shadow and the
z-index that puts the stack over a modal — and names its severity with a colored
rail and an icon instead of a solid `text-bg-*` fill: a fill carries neither a
readable link nor a long line, and in the dark theme a thin border in its place
all but disappears (mockups/components/toast). Bootstrap classes only; what stock
utilities cannot express lives in hilos-styles.scss (styling-rules.md). -->
<script setup lang="ts">
import type { ComponentPublicInstance } from 'vue'
import { computed, onMounted, onUnmounted, onUpdated, reactive, ref } from 'vue'
import type {
  HilosToast,
  HilosToastSeverity,
  HilosToastStore,
  HilosToastViewer,
} from '@hilos/core'
import { hilosToasts } from '@hilos/core'

import HilosLink from './HilosLink.vue'
import type { HilosToastCorner } from './hilosToastCorner.js'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The stack to render; defaults to the application-wide store. */
  store?: HilosToastStore
  /** Which corner the stack sits in; defaults to the bottom end. */
  corner?: HilosToastCorner
}>()

const store = props.store ?? hilosToasts
const toasts = useSignal(store.toasts)
const overflow = useSignal(store.overflow)

// The measuring layer: a card the store has not measured yet is still rendered —
// it has to be, to be measured — but it is taken out of the flow and out of
// sight until the store admits it. Not `d-none`, which reports zero height and
// breaks the measurement, and not `invisible`, which drops the card out of the
// accessibility tree and silences the announcement; `opacity-0` does neither.
// `pe-none` so a card nobody can see cannot take the cursor from the ones that
// are visible and hand the store a hold on their countdown.
const MEASURING_LAYER = 'position-absolute opacity-0 pe-none'

// What names a severity on the light card: the color of the rail down its left
// edge, the color of its icon, and the icon itself. One table of the three
// rather than three lookups or class strings glued together in the template —
// glued classes read badly and cannot be grepped (CONN_VISUAL in HilosLayout is
// the same shape).
type ToastVisual = { rail: string; accent: string; icon: string }
const TOAST_VISUAL: Record<HilosToastSeverity, ToastVisual> = {
  error: {
    rail: 'border-danger',
    accent: 'text-danger',
    icon: 'bi-x-circle-fill',
  },
  success: {
    rail: 'border-success',
    accent: 'text-success',
    icon: 'bi-check-circle-fill',
  },
  warning: {
    rail: 'border-warning',
    accent: 'text-warning',
    icon: 'bi-exclamation-triangle-fill',
  },
  info: {
    rail: 'border-primary',
    accent: 'text-primary',
    icon: 'bi-info-circle-fill',
  },
}

// Where the stack sits: the horizontal edge is a stock Bootstrap utility, the
// vertical one is not, because the narrow screen overrides it and the stock
// .bottom-0 carries !important that a media query cannot outrank.
const corner = computed(() => props.corner ?? 'bottom-end')
const cornerClasses = computed(() => [
  corner.value.endsWith('-end') ? 'end-0' : 'start-0',
  corner.value.startsWith('bottom-')
    ? 'hilos-toast-stack-bottom'
    : 'hilos-toast-stack-top',
])

// What the service line under the stack says: the errors still queued and the
// notices that were dropped. A piece appears only when it has something to
// report, and the joined pieces are the canon's wording rather than this host's
// (docs/agents/frontend/toasts.md). The store zeroes both numbers itself once
// the stack empties, so the line goes away without the host doing anything.
const overflowLine = computed(() => {
  const pieces: string[] = []

  if (overflow.value.waiting > 0) {
    pieces.push(`${overflow.value.waiting} more waiting`)
  }
  if (overflow.value.missed > 0) {
    pieces.push(`${overflow.value.missed} missed`)
  }

  return pieces.join(' · ')
})

// What the live regions say. A notice reaches them only once it is measured:
// until then the store may still take it into the queue or into the missed
// count, and announcing a card that will not appear promises something that can
// be neither read nor dismissed. A repeat does not change the text, so twenty
// identical failures are read once — the merge, carried over to hearing.
const announced = computed(() => toasts.value.filter((toast) => toast.measured))
const spokenErrors = computed(() =>
  announced.value.filter((toast) => toast.severity === 'error'),
)
const spokenRest = computed(() =>
  announced.value.filter((toast) => toast.severity !== 'error'),
)

/**
 * The line a screen reader reads out for one notice.
 *
 * A background notice names its sender: the listener would otherwise get news
 * with no idea who sent it, while the reader of the card sees the signature.
 *
 * @param toast The notice being announced.
 */
function spoken(toast: HilosToast): string {
  return toast.source === null
    ? toast.message
    : `${toast.source}: ${toast.message}`
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
const stack = ref<HTMLElement | null>(null)

// The three holds this host owns, and whether it is holding each right now: the
// cursor over the stack, the keyboard focus inside it, and a tab nobody is
// looking at — walk away and everything is still there when you come back. The
// kind travels with each hold (HIL-768): the store freezes on all three alike
// but reports only the two a person is behind. It
// tracks them because the host owes the store exactly one hold of each kind
// (mouseover and visibilitychange both fire more than once), and it keeps them
// reactive because the life bar draws the freeze from this very counter: the
// bar cannot drift apart from what the store was told, since both come from
// here.
const held = reactive({ cursor: false, focus: false, tab: false })
const frozen = computed(() => held.cursor || held.focus || held.tab)

function onResize(): void {
  viewer?.setViewportHeight(window.innerHeight)
}

function onVisibility(): void {
  if (viewer === null || document.hidden === held.tab) {
    return
  }
  held.tab = document.hidden
  if (held.tab) {
    viewer.hold('tab')

    return
  }
  viewer.release('tab')
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

/**
 * Take the cursor's hold if the stack turns out to be under the pointer.
 *
 * One-way on purpose: it only ever takes the hold, never gives it back. The
 * host also releases that hold by hand when a card is dismissed (a card closing
 * under the pointer takes its own mouseleave with it), so a stack still under
 * the pointer has to get the hold back without waiting for the reader to move
 * the mouse. Releasing here instead would need an environment that answers
 * `:hover`, which jsdom is not, and removal re-checks honestly anyway.
 */
function holdIfUnderCursor(): void {
  if (stack.value !== null && stack.value.matches(':hover')) {
    holdOnCursor()
  }
}

onMounted(() => {
  viewer = store.attach()
  viewer.setViewportHeight(window.innerHeight)
  onVisibility()
  window.addEventListener('resize', onResize)
  document.addEventListener('visibilitychange', onVisibility)
  reportHeights()
  holdIfUnderCursor()
})

onUpdated(() => {
  reportHeights()
  holdIfUnderCursor()
})

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
function holdOnCursor(): void {
  if (viewer !== null && !held.cursor) {
    held.cursor = true
    viewer.hold('cursor')
  }
}

function releaseCursorHold(): void {
  if (held.cursor) {
    held.cursor = false
    viewer?.release('cursor')
  }
}

function releaseFocusHold(): void {
  if (held.focus) {
    held.focus = false
    viewer?.release('focus')
  }
}

function holdOnFocus(event: FocusEvent): void {
  if (viewer !== null && !held.focus && !movesWithin(event)) {
    held.focus = true
    viewer.hold('focus')
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

/**
 * Close the card whose link just took the reader somewhere else.
 *
 * Which clicks those are is not decided here a second time: HilosLink swallows
 * the event exactly when it navigated in place, while a modified click, a
 * non-primary button and a missing router leave it alone — and in those the
 * reader never left this page, so the notice stays. The host's handler runs
 * after the link's own, which is what makes the swallowed event visible here.
 *
 * @param event The click on the card's link.
 * @param id The toast's id.
 */
function dismissIfNavigated(event: MouseEvent, id: number): void {
  if (event.defaultPrevented) {
    dismiss(id)
  }
}
</script>

<template>
  <!-- The two live regions, declared in advance and on the stack rather than on
  the card that just appeared: a role attached to a freshly inserted node leaves
  part of the screen readers silent. Two of them, because only a failure is
  allowed to interrupt what the listener is hearing. They sit OUTSIDE the stack
  container: inside, they would double every visible line for a search by text,
  which is how the demo suites find a notice. -->
  <div
    class="visually-hidden"
    role="alert"
    aria-live="assertive"
    data-id="hilos-toast-live-assertive"
  >
    <div v-for="toast in spokenErrors" :key="toast.id">{{ spoken(toast) }}</div>
  </div>
  <div
    class="visually-hidden"
    role="status"
    aria-live="polite"
    data-id="hilos-toast-live-polite"
  >
    <div v-for="toast in spokenRest" :key="toast.id">{{ spoken(toast) }}</div>
  </div>
  <div
    ref="stack"
    class="toast-container position-fixed p-3"
    :class="cornerClasses"
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
      class="toast fade show overflow-hidden"
      :class="toast.measured ? '' : MEASURING_LAYER"
      :data-id="`hilos-toast-${toast.severity}`"
    >
      <!-- The card's one positioned box: the rail, the icon and the text sit in
      it, and it is what the message's stretched link covers, so the whole card
      leads where the notice points while the close button stays clickable over
      it (.z-2). -->
      <div
        class="border-start border-4 d-flex align-items-start gap-2 p-2 position-relative"
        :class="TOAST_VISUAL[toast.severity].rail"
      >
        <i
          class="bi ms-1"
          :class="[
            TOAST_VISUAL[toast.severity].icon,
            TOAST_VISUAL[toast.severity].accent,
          ]"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1 min-w-0">
          <div
            v-if="toast.source !== null"
            class="hilos-toast-source text-body-secondary mb-1"
            data-id="hilos-toast-source"
          >
            {{ toast.source }}
          </div>
          <div class="d-flex align-items-start gap-2">
            <div class="hilos-toast-clamp flex-grow-1 min-w-0">
              <HilosLink
                v-if="toast.destination !== null"
                :to="toast.destination"
                class="stretched-link link-body-emphasis text-decoration-none"
                @click="dismissIfNavigated($event, toast.id)"
              >
                {{ toast.message }}
              </HilosLink>
              <template v-else>{{ toast.message }}</template>
            </div>
            <span
              v-if="toast.repeats > 1"
              class="badge text-bg-light border"
              data-id="hilos-toast-repeats"
              >×{{ toast.repeats }}</span
            >
          </div>
        </div>
        <button
          type="button"
          class="btn-close position-relative z-2"
          aria-label="Close"
          data-id="hilos-toast-close"
          @click="dismiss(toast.id)"
        ></button>
      </div>
      <!-- The life bar, and only where a countdown actually runs: an error never
      expires, and a card the host has not reported yet has no countdown started.
      It is keyed by the repeat count because a merge gives the notice its full
      time back — without a fresh node the animation would finish the old round
      and show a time that is not the one running. -->
      <div
        v-if="toast.measured && toast.severity !== 'error'"
        :key="toast.repeats"
        class="hilos-toast-life"
        :class="[
          TOAST_VISUAL[toast.severity].accent,
          { 'hilos-toast-life-paused': frozen },
        ]"
        data-id="hilos-toast-life"
      ></div>
    </div>
    <!-- The service line: how many errors are still queued and how many notices
    were dropped, under the newest card. It carries no ref, so it never reaches
    reportHeight() and never counts toward the height cap — a line that says what
    did not fit must not push out what did. It carries `pe-auto` because
    `.toast-container` turns pointer events off and only `.toast` turns them back
    on: without it the line would be the one part of the stack that the cursor
    resting on it does not count as reading. -->
    <div
      v-if="overflow.waiting > 0 || overflow.missed > 0"
      class="bg-body border rounded-3 shadow-sm px-2 py-1 small text-body-secondary pe-auto"
      data-id="hilos-toast-overflow"
    >
      <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i
      >{{ overflowLine }}
    </div>
  </div>
</template>
