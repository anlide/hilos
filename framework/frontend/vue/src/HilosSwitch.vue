<!-- HilosSwitch — an authoritative-backend switch. A click reports the next
value but never moves the checkbox itself; the checked input follows only the
value its owner received from the backend. The shared LoadingButton controller
delays the busy spinner so a fast reply does not flash it. -->
<script setup lang="ts">
import { computed, onBeforeUnmount, useId, watch } from 'vue'
import { DEFAULT_SPINNER_DELAY_MS, createLoadingButtonState } from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The backend-confirmed position of the switch. */
    checked: boolean
    /** Whether this switch's action is in flight. */
    busy?: boolean
    /** Disable the switch for a reason other than its own action. */
    disabled?: boolean
    /** The stable selector placed on the checkbox. */
    dataId: string
    /** The visible label, when the surface has one. */
    label?: string
    /** The accessible name when no visible label is drawn. */
    ariaLabel?: string
    /** The id of the hint describing the switch. */
    describedBy?: string
    /** Milliseconds to wait before showing the busy spinner. */
    spinnerDelay?: number
  }>(),
  {
    busy: false,
    disabled: false,
    label: undefined,
    ariaLabel: undefined,
    describedBy: undefined,
    spinnerDelay: DEFAULT_SPINNER_DELAY_MS,
  },
)

const emit = defineEmits<{ toggle: [next: boolean] }>()
const id = useId()

const spinner = createLoadingButtonState(() => props.spinnerDelay)
const showSpinner = useSignal(spinner.showSpinner)
watch(
  () => props.busy,
  (busy) => spinner.setLoading(busy),
  { immediate: true },
)
onBeforeUnmount(spinner.dispose)

const isDisabled = computed(() => props.disabled || props.busy)

function onClick(event: MouseEvent): void {
  event.preventDefault()
  emit('toggle', !props.checked)
}
</script>

<template>
  <div class="form-check form-switch">
    <input
      :id="id"
      class="form-check-input"
      type="checkbox"
      role="switch"
      :checked="checked"
      :disabled="isDisabled"
      :aria-busy="busy || undefined"
      :aria-label="ariaLabel"
      :aria-describedby="describedBy"
      :data-id="dataId"
      @click="onClick"
    />
    <label v-if="label !== undefined" class="form-check-label" :for="id">
      {{ label }}
    </label>
    <span
      v-if="showSpinner"
      class="spinner-border spinner-border-sm ms-2 align-middle"
      role="status"
    >
      <span class="visually-hidden">Saving…</span>
    </span>
  </div>
</template>
