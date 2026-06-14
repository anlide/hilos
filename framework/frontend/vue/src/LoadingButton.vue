<!-- LoadingButton — a button that disables itself and shows a spinner while an
async action is in flight (the authoritative-backend pattern: act → block →
backend reply clears it). The spinner appears only after a short delay so a fast
reply never flashes it, and the label stays in the layout under the spinner so
the button keeps its width. Pass the Bootstrap variant as a class
(`class="btn-primary"`); class, aria, and data attributes fall through to the
button. -->
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'

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
  { loading: false, disabled: false, loadingDelay: 300, type: 'button' },
)

const emit = defineEmits<{ click: [event: MouseEvent] }>()

const showSpinner = ref(false)
let spinnerTimer: ReturnType<typeof setTimeout> | undefined

function clearSpinnerTimer(): void {
  if (spinnerTimer !== undefined) {
    clearTimeout(spinnerTimer)
    spinnerTimer = undefined
  }
}

watch(
  () => props.loading,
  (loading) => {
    clearSpinnerTimer()
    if (loading) {
      spinnerTimer = setTimeout(() => {
        showSpinner.value = true
      }, props.loadingDelay)
    } else {
      showSpinner.value = false
    }
  },
)

onBeforeUnmount(clearSpinnerTimer)

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
