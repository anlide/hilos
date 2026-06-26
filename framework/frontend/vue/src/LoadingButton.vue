<!-- LoadingButton — a button that disables itself and shows a spinner while an
async action is in flight (the authoritative-backend pattern: act -> block ->
backend reply clears it). The delayed-spinner timer is the headless core state
machine (createLoadingButtonState); this view only drives `loading` into it and
renders `showSpinner`. The spinner appears only after a short delay so a fast
reply never flashes it, and the label stays in the layout under the spinner so
the button keeps its width. Pass the Bootstrap variant as a class
(`class="btn-primary"`); class, aria, and data attributes fall through to the
button. -->
<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { useSignal } from './useSignal.js'

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
  }>(),
  {
    loading: false,
    disabled: false,
    loadingDelay: DEFAULT_SPINNER_DELAY_MS,
    type: 'button',
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

const isDisabled = computed(() => props.disabled || props.loading)

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
