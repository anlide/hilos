<!-- LoadingButton — a button that disables itself and shows a spinner while an
async action is in flight (the authoritative-backend pattern: act -> block ->
backend reply clears it). The delayed-spinner timer is the headless core state
machine (createLoadingButtonState); this view only drives `loading` into it and
renders `showSpinner`. The spinner appears only after a short delay so a fast
reply never flashes it, and the label stays in the layout under the spinner so
the button keeps its width. Pass the Bootstrap variant as a class
(`class="btn-primary"`); class, aria, and data attributes fall through to the
button. Inside an admin page a viewer of the admin view mode finds the button
plainly disabled, described by the mode's strip (HIL-1261), and so does an
administrator in the page's area of a takeover that only looks, described by
the impersonation strip (HIL-1170); it changes neither its color, nor its size,
nor its words. A button marked opensWindow stays live for both (the people
card, HIL-1263). -->
<script setup lang="ts">
import { computed, onBeforeUnmount, useAttrs, watch } from 'vue'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { useLookOnly } from './hilosAdminViewMode.js'
import { useSignal } from './useSignal.js'

// The attributes are bound by hand: a fallen-through aria-describedby would
// override the button's own, and when only looking the two are joined instead.
defineOptions({ inheritAttrs: false })

const props = withDefaults(
  defineProps<{
    /** Whether the action is in flight: disables the button and arms the spinner. */
    loading?: boolean
    /** Disable independently of loading (e.g. an invalid form). */
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

const isDisabled = computed(
  () => props.disabled || props.loading || lockedToLook.value,
)

// While the button may be pressed the caller's attributes reach it as they are.
// Locked to look, it is also described by the strip that says why, beside
// whatever describes it already (a row's reason on the person's card).
const buttonAttrs = computed(() => {
  const strip = describedBy.value
  if (!lockedToLook.value || strip === undefined) {
    return attrs
  }
  const own = attrs['aria-describedby']

  return {
    ...attrs,
    'aria-describedby':
      typeof own === 'string' && own !== '' ? `${own} ${strip}` : strip,
  }
})

function onClick(event: MouseEvent): void {
  if (isDisabled.value) {
    return
  }
  emit('click', event)
}
</script>

<template>
  <button
    :type="type"
    :disabled="isDisabled"
    :aria-busy="loading || undefined"
    class="btn position-relative"
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
