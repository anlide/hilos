<template>
  <button
      :type="type"
      class="btn position-relative"
      :class="buttonClass"
      :disabled="isDisabled"
      @click="handleClick"
  >
    <span>
      <slot />
    </span>
    <!-- Spinner overlay shown after loadingDelay when loading -->
    <span
        v-if="showSpinner"
        class="position-absolute top-50 start-50 translate-middle"
        aria-hidden="true"
    >
      <span
          class="spinner-border spinner-border-sm"
          role="status"
          aria-label="Loading"
      >
        <span class="visually-hidden">Loading</span>
      </span>
    </span>
  </button>
</template>

<script setup lang="ts">
import { ref, watch, onBeforeUnmount, computed } from 'vue'

const props = withDefaults(
    defineProps<{
      loading?: boolean
      disabled?: boolean
      loadingDelay?: number
      /** Bootstrap button variant classes, e.g. 'btn-primary' */
      variant?: string
      type?: 'button' | 'submit' | 'reset'
    }>(),
    {
      loading: false,
      disabled: false,
      loadingDelay: 300,
      variant: 'btn-primary',
      type: 'button',
    }
)

const emit = defineEmits<{
  click: [event: MouseEvent]
}>()

/** True when we have been in loading state for at least loadingDelay ms */
const showSpinner = ref(false)
let loadingDelayTimer: ReturnType<typeof setTimeout> | null = null

const isDisabled = computed(() => props.disabled || props.loading)

const buttonClass = computed(() => props.variant)

function startLoadingDelayTimer() {
  clearLoadingDelayTimer()
  loadingDelayTimer = setTimeout(() => {
    showSpinner.value = true
    loadingDelayTimer = null
  }, props.loadingDelay)
}

function clearLoadingDelayTimer() {
  if (loadingDelayTimer !== null) {
    clearTimeout(loadingDelayTimer)
    loadingDelayTimer = null
  }
}

watch(
    () => props.loading,
    (isLoading) => {
      if (isLoading) {
        startLoadingDelayTimer()
      } else {
        clearLoadingDelayTimer()
        showSpinner.value = false
      }
    }
)

onBeforeUnmount(clearLoadingDelayTimer)

function handleClick(event: MouseEvent) {
  if (isDisabled.value) return
  emit('click', event)
}
</script>
