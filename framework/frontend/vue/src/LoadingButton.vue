<!-- LoadingButton — a button that disables itself and shows a spinner while an
async action is in flight (the authoritative-backend pattern: act -> block ->
backend reply clears it). The delayed-spinner timer is the headless core state
machine (createLoadingButtonState); this view only drives `loading` into it and
renders `showSpinner`. The spinner appears only after a short delay so a fast
reply never flashes it, and the label stays in the layout under the spinner so
the button keeps its width. Pass the Bootstrap variant as a class
(`class="btn-primary"`); class, aria, and data attributes fall through to the
button. Inside an admin page a viewer of the admin view mode finds the button
plainly disabled, described by the mode's strip (HIL-1261); it changes neither
its color, nor its size, nor its words. A button marked opensWindow stays live
for the viewer (the people card, HIL-1263). -->
<script setup lang="ts">
import { computed, onBeforeUnmount, useAttrs, watch } from 'vue'
import {
  DEFAULT_SPINNER_DELAY_MS,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  createLoadingButtonState,
} from '@hilos/core'

import { useAdminViewMode } from './hilosAdminViewMode.js'
import { useSignal } from './useSignal.js'

// The attributes are bound by hand: a fallen-through aria-describedby would
// override the button's own, and in the view mode the two are joined instead.
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
     * The press only opens a window, at once or after the server's word; inside
     * an admin page the admin view mode leaves it live — what the window would
     * send stands on a control of the mode.
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

const viewMode = useAdminViewMode()
const attrs = useAttrs()

const lockedByViewMode = computed(() => viewMode.value && !props.opensWindow)

const isDisabled = computed(
  () => props.disabled || props.loading || lockedByViewMode.value,
)

// Outside the view mode the caller's attributes reach the button as they are.
// In it, the button is also described by the mode's strip, beside whatever
// describes it already (a row's reason on the person's card).
const buttonAttrs = computed(() => {
  if (!lockedByViewMode.value) {
    return attrs
  }
  const own = attrs['aria-describedby']

  return {
    ...attrs,
    'aria-describedby':
      typeof own === 'string' && own !== ''
        ? `${own} ${HILOS_VIEW_MODE_STRIP_TEXT_ID}`
        : HILOS_VIEW_MODE_STRIP_TEXT_ID,
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
