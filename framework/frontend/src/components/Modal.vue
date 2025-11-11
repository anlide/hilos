<template>
  <div
    class="modal fade"
    :class="{ show: visible }"
    tabindex="-1"
    role="dialog"
    :style="{ display: visible ? 'block' : 'none' }"
    :data-modal-name="modalName || null"
    @click.self="onCancel"
    @keyup.esc="onCancel"
  >
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <!-- Header -->
        <div class="modal-header">
          <slot name="header">
            <h5 class="modal-title">{{ title }}</h5>
          </slot>
          <button type="button" class="btn-close" aria-label="Close" @click="onCancel"></button>
        </div>

        <!-- Main content -->
        <div class="modal-body">
          <slot>
            <!-- Default content if slot is not overridden -->
          </slot>
        </div>

        <!-- Footer -->
        <div class="modal-footer">
          <slot name="actions">
            <button type="button" class="btn btn-secondary" @click="onCancel">Cancel</button>
            <button type="button" class="btn btn-primary" @click="onOk">OK</button>
          </slot>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts" setup>
// Note: Vue types are resolved by demo projects via tsconfig.app.json
// IDE may show errors here, but TypeScript compiler in demo projects will resolve them correctly
// @ts-expect-error - Vue types are provided by demo project's node_modules
import { computed, defineProps, defineEmits, onBeforeUnmount, watch } from 'vue'

interface Props {
  modelValue: boolean
  title?: string
  closeOnEsc?: boolean // default true
  closeOnBackdrop?: boolean // default true
  modalName?: string // Modal name for e2e tests (data-modal-name)
}

const props = defineProps<Props>()
const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'ok'): void
  (e: 'cancel'): void
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const onOk = () => {
  emit('ok')
  visible.value = false
}

const onCancel = () => {
  emit('cancel')
  visible.value = false
}

// ESC key handler
const onKeydown = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && props.closeOnEsc !== false) {
    onCancel()
  }
}

// Auto-focus when modal opens
const setAutoFocus = () => {
  // Find current modal by modalName or by class
  const currentModal = document.querySelector(`[data-modal-name="${props.modalName || ''}"]`) ||
    document.querySelector('.modal.show')

  if (!currentModal) return

  // Find all focusable elements ONLY inside modal
  // Exclude footer buttons as they will be handled separately in fallback
  const focusableSelectors = [
    '.modal-body button:not([disabled])',
    '.modal-body input:not([disabled])',
    '.modal-body select:not([disabled])',
    '.modal-body textarea:not([disabled])',
    '.modal-body a[href]',
    '.modal-body [tabindex]:not([tabindex="-1"])'
  ].join(', ')

  const focusableElements = currentModal.querySelectorAll(focusableSelectors)

  if (focusableElements.length === 0) {
    // If no focusable elements, look for buttons in footer (actions)
    // Consider LoadingButton wrapper - look for buttons inside .modal-footer
    const actionButtons = currentModal.querySelectorAll('.modal-footer button:not([disabled])')
    if (actionButtons.length > 0) {
      // Focus on last active button in actions
      (actionButtons[actionButtons.length - 1] as HTMLElement).focus()
    } else {
      // If no buttons in actions, focus on close button
      const closeButton = currentModal.querySelector('.modal-header .btn-close') as HTMLElement
      if (closeButton) {
        closeButton.focus()
      }
    }
    return
  }

  // Look for elements with data-autofocus
  const autofocusElements = Array.from(focusableElements).filter(el =>
    el.hasAttribute('data-autofocus')
  )

  if (autofocusElements.length > 0) {
    // Focus on first element with data-autofocus
    (autofocusElements[0] as HTMLElement).focus()
  } else {
    // Focus on first available element
    (focusableElements[0] as HTMLElement).focus()
  }
}

watch(
  () => visible.value,
  (val) => {
    if (val) {
      document.body.classList.add('modal-open')
      document.addEventListener('keydown', onKeydown)
      // Set auto-focus after next tick so DOM has time to update
      setTimeout(setAutoFocus, 0)
    } else {
      document.body.classList.remove('modal-open')
      document.removeEventListener('keydown', onKeydown)
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  document.body.classList.remove('modal-open')
  document.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
/*
  Use Bootstrap Modal class, but "highlight background":
  1) background-color: rgba(0,0,0,0.5) - semi-transparent black background
  2) backdrop-filter: blur(2px) - light blur (can be disabled if not needed)
  3) z-index: 1050 - matches base z-index of dropdowns and other elements
*/
.modal {
  background-color: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(2px);
  z-index: 1050;
}

/* When modal is active, .show class is added */
/* display: block appears via inline style in template */

/* Modal content (Bootstrap standard) */
/* Here you can leave everything as is - Bootstrap will set needed sizes */
/* .modal-content, .modal-header, .modal-body, .modal-footer - all pulled from Bootstrap CSS */

/* Prevent main page scrolling when modal is open */
body.modal-open {
  overflow: hidden;
}
</style>

