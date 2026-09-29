<!-- HilosSwitch — an authoritative-backend switch. A click reports the next
value but never moves the checkbox itself; the checked input follows only the
value its owner received from the backend. The shared LoadingButton controller
delays the busy spinner so a fast reply does not flash it. Inside an admin page a
viewer of the admin view mode finds it plainly disabled in the position the
server sent, described by the mode's strip besides its own hint (HIL-1261). -->
<script setup lang="ts">
import { computed, onBeforeUnmount, useId, watch } from 'vue'
import {
  DEFAULT_SPINNER_DELAY_MS,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  createLoadingButtonState,
} from '@hilos/core'

import { useAdminViewMode } from './hilosAdminViewMode.js'
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

const viewMode = useAdminViewMode()

const isDisabled = computed(
  () => props.disabled || props.busy || viewMode.value,
)

const ariaDescribedBy = computed(() => {
  if (!viewMode.value) {
    return props.describedBy
  }

  return props.describedBy === undefined
    ? HILOS_VIEW_MODE_STRIP_TEXT_ID
    : `${props.describedBy} ${HILOS_VIEW_MODE_STRIP_TEXT_ID}`
})

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
      :aria-describedby="ariaDescribedBy"
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
