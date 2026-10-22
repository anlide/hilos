<!-- LoadingButton — a button that locks itself the moment its async action is in
flight and shows a spinner while it stays there (the authoritative-backend
pattern: act -> block -> backend reply clears it). The lock is at once — a
second press is swallowed and the button says it is busy — but the look is not:
the button keeps its look, and its focus, until the spinner comes, and only then
looks disabled, so a fast reply changes nothing on screen. The delayed-spinner
timer is the headless core state machine (createLoadingButtonState); this view
only drives `loading` into it and renders `showSpinner`. The label stays in the
layout under the spinner so the button keeps its width. Pass the Bootstrap
variant as a class
(`class="btn-primary"`); class, aria, and data attributes fall through to the
button. Inside an admin page a viewer of the admin view mode finds the button
plainly disabled, described by the mode's strip (HIL-1261), and so does an
administrator in the page's area of a takeover that only looks, described by
the impersonation strip (HIL-1170); it changes neither its color, nor its size,
nor its words. A button marked opensWindow stays live for both (the people
card, HIL-1263). -->
<script setup lang="ts">
import { computed, onBeforeUnmount, useAttrs, watch } from 'vue'
import {
  DEFAULT_SPINNER_DELAY_MS,
  createLoadingButtonState,
} from '@hilos/core/base'

import { useLookOnly } from './hilosAdminViewMode.js'
import { useSignal } from './useSignal.js'

// The attributes are bound by hand: a fallen-through aria-describedby would
// override the button's own, and when only looking the two are joined instead.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    /**
     * Whether the button's own action is in flight — the immediate flag, raised
     * on the press and lowered by the reply, never a deferred one: the delay is
     * the button's own timer. It locks the button at once (busy, a press
     * swallowed) and arms the spinner; the disabled look comes with the spinner.
     */
    loading?: boolean
    /**
     * An independent reason the button may not be pressed — a form not yet
     * filled, a screen that only looks — never the button's own action in
     * flight. Disables it natively at once, while it loads as well.
     */
    disabled?: boolean
    /** Milliseconds to wait before showing the spinner, so a fast reply never flashes it. */
    loadingDelay?: number
    /** Native button type; `submit` inside a form, `button` otherwise. */
    type?: 'button' | 'submit' | 'reset'
    /**
     * The press only opens a window, at once or after the server's word; the
     * admin view mode and a takeover that only looks leave it live — what the
     * window would send stands on a control of the mode.
     */
    opensWindow?: boolean
  }>(),
  {
    loading: false,
    disabled: false,
    loadingDelay: DEFAULT_SPINNER_DELAY_MS,
    type: 'button',
    opensWindow: false,
  },
)

const emit = defineEmits<{ click: [event: MouseEvent] }>()

const spinner = createLoadingButtonState(() => props.loadingDelay)
const showSpinner = useSignal(spinner.showSpinner)
watch(
  () => props.loading,
  (loading) => spinner.setLoading(loading),
  {
    immediate: true,
  },
)
onBeforeUnmount(spinner.dispose)

const { locked, describedBy } = useLookOnly()
const attrs = useAttrs()

const lockedToLook = computed(() => locked.value && !props.opensWindow)

// A reason other than its own action turns the button off natively from the
// first moment; its own action in flight only blocks it, so the focus stays on
// it and its look holds until the spinner comes.
const nativeDisabled = computed(() => props.disabled || lockedToLook.value)
const blocked = computed(() => nativeDisabled.value || props.loading)

// The caller's attributes reach the button as they are, save that none of them
// takes back the busy or blocked state the button says of itself. Locked to
// look, it is also described by the strip that says why, beside whatever
// describes it already (a row's reason on the person's card).
const buttonAttrs = computed(() => {
  // Read on every pass: a read is what ties this computed to the caller's
  // attributes, and spreading none of them reads nothing.
  const theirs = attrs['aria-describedby']
  const strip = describedBy.value
  const own: Record<string, unknown> = {}
  if (lockedToLook.value && strip !== undefined) {
    own['aria-describedby'] =
      typeof theirs === 'string' && theirs !== '' ? `${theirs} ${strip}` : strip
  }
  if (props.loading) {
    own['aria-busy'] = true
  }
  if (blocked.value) {
    own['aria-disabled'] = true
  }

  return { ...attrs, ...own }
})

// A blocked press is canceled before it leaves: no submit of the button's
// form, by a click or by Enter in a field, and no click on its ancestors.
function onClick(event: MouseEvent): void {
  if (blocked.value) {
    event.preventDefault()
    event.stopPropagation()

    return
  }
  emit('click', event)
}
</script>

<template>
  <button
    :type="type"
    :disabled="nativeDisabled"
    class="btn position-relative"
    :class="{ disabled: showSpinner }"
    v-bind="buttonAttrs"
    @click="onClick"
  >
    <span :class="{ invisible: showSpinner }"><slot /></span>
    <span
      v-if="showSpinner"
      class="position-absolute top-50 start-50 translate-middle"
      data-id="loading-button-spinner"
    >
      <span class="spinner-border spinner-border-sm" role="status">
        <span class="visually-hidden">Loading…</span>
      </span>
    </span>
  </button>
</template>
