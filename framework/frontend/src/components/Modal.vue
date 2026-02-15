<template>
  <Teleport to="body">
    <div
        class="modal fade"
        :class="{ show: visible }"
        tabindex="-1"
        role="dialog"
        :style="modalStyle"
        :data-modal-id="modalId"
        :data-modal-name="modalName || null"
        :data-modal-type="modalType"
        @click="onBackdropClick"
        @keydown.esc="onEscFromModal"
    >
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <!-- Header -->
          <div class="modal-header">
            <slot name="header">
              <h5 class="modal-title">{{ title }}</h5>
            </slot>
            <button type="button" class="btn-close" aria-label="Close" @click="requestClose"></button>
          </div>

          <!-- Main content -->
          <div class="modal-body">
            <slot>
              <!-- Default content if slot is not overridden -->
            </slot>
          </div>

          <!-- Footer -->
          <div class="modal-footer">
            <slot name="actions" :requestClose="requestClose">
              <button type="button" class="btn btn-secondary" @click="requestClose">Cancel</button>
              <button type="button" class="btn btn-primary" @click="onOk">OK</button>
            </slot>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm close modal -->
    <div
        v-if="confirmVisible"
        class="modal fade confirm-modal"
        :class="{ show: confirmVisible }"
        tabindex="-1"
        role="dialog"
        :style="confirmStyle"
        :data-modal-id="confirmModalId"
        :data-modal-type="'confirm'"
        @click="onConfirmBackdropClick"
        @keydown.esc="onEscFromConfirm"
    >
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">{{ confirmTitleComputed }}</h5>
            <button type="button" class="btn-close" aria-label="Close" @click="onConfirmCancel"></button>
          </div>
          <div class="modal-body">
            <p class="mb-0">{{ confirmMessageComputed }}</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="onConfirmCancel">
              {{ confirmCancelTextComputed }}
            </button>
            <button type="button" class="btn btn-danger" @click="onConfirmOk">
              {{ confirmOkTextComputed }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script lang="ts" setup>
// Note: Vue types are resolved by demo projects via tsconfig.app.json
// IDE may show errors here, but TypeScript compiler in demo projects will resolve them correctly
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const modalStack: string[] = []
const stackVersion = ref(0)

const pushStack = (id: string) => {
  if (!modalStack.includes(id)) {
    modalStack.push(id)
    stackVersion.value++
  }
}

const removeStack = (id: string) => {
  const index = modalStack.indexOf(id)
  if (index !== -1) {
    modalStack.splice(index, 1)
    stackVersion.value++
  }
}

const isTopmost = (id: string) => {
  return modalStack[modalStack.length - 1] === id
}

const getStackIndex = (id: string) => {
  stackVersion.value
  return modalStack.indexOf(id)
}

const updateBodyScrollLock = () => {
  if (modalStack.length > 0) {
    document.body.classList.add('modal-open')
  } else {
    document.body.classList.remove('modal-open')
  }
}

interface Props {
  modelValue: boolean
  title?: string
  closeOnEsc?: boolean // default true
  closeOnBackdrop?: boolean // default true
  modalName?: string // Modal name for e2e tests (data-modal-name)
  modalType?: 'custom' | 'add' | 'edit' | 'delete'
  confirmOnClose?: boolean
  confirmTitle?: string
  confirmMessage?: string
  confirmOkText?: string
  confirmCancelText?: string
}

const props = withDefaults(defineProps<Props>(), {
  closeOnEsc: true,
  closeOnBackdrop: true,
  confirmOnClose: false,
  modalType: 'custom',
})
const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'ok'): void
  (e: 'cancel'): void
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const confirmVisible = ref(false)
const modalId = `modal-${Math.random().toString(36).slice(2)}`
const confirmModalId = `${modalId}-confirm`

const modalType = computed(() => props.modalType)
const confirmTitleComputed = computed(() => props.confirmTitle ?? 'Discard changes?')
const confirmMessageComputed = computed(() => props.confirmMessage ?? 'You have unsaved changes. Close anyway?')
const confirmOkTextComputed = computed(() => props.confirmOkText ?? 'Discard')
const confirmCancelTextComputed = computed(() => props.confirmCancelText ?? 'Keep editing')

const modalStyle = computed(() => ({
  display: visible.value ? 'block' : 'none',
  zIndex: 1050 + Math.max(getStackIndex(modalId), 0) * 20,
}))

const confirmStyle = computed(() => ({
  display: confirmVisible.value ? 'block' : 'none',
  zIndex: 1050 + Math.max(getStackIndex(confirmModalId), 0) * 20,
}))

const requestClose = () => {
  if (!isTopmost(modalId)) return
  if (props.confirmOnClose) {
    if (!confirmVisible.value) {
      confirmVisible.value = true
    }
    return
  }
  onCancel()
}

const onOk = () => {
  emit('ok')
  visible.value = false
}

const onCancel = () => {
  emit('cancel')
  visible.value = false
}

const onBackdropClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement | null
  const currentTarget = event.currentTarget as HTMLElement | null
  const dialog = currentTarget?.querySelector('.modal-dialog') || null
  if (!props.closeOnBackdrop) return
  if (!isTopmost(modalId)) return

  if (!currentTarget) return

  if (dialog && target && dialog.contains(target)) {
    return
  }

  requestClose()
}

const onConfirmBackdropClick = (event: MouseEvent) => {
  if (!isTopmost(confirmModalId)) return
  const target = event.target as HTMLElement | null
  const currentTarget = event.currentTarget as HTMLElement | null
  if (!currentTarget) return

  const dialog = currentTarget.querySelector('.modal-dialog')
  if (dialog && target && dialog.contains(target)) {
    return
  }

  onConfirmCancel()
}

const onEscFromModal = () => {
  if (!props.closeOnEsc) return
  if (!isTopmost(modalId)) return
  requestClose()
}

const onEscFromConfirm = () => {
  if (!isTopmost(confirmModalId)) return
  onConfirmCancel()
}

const onConfirmOk = () => {
  confirmVisible.value = false
  onCancel()
}

const onConfirmCancel = () => {
  confirmVisible.value = false
}

const getModalElement = (id: string) => {
  return document.querySelector(`[data-modal-id="${id}"]`)
}

// ESC key handler
const onKeydown = (e: KeyboardEvent) => {
  if (e.key === 'Escape') {
    if (confirmVisible.value && isTopmost(confirmModalId)) {
      onConfirmCancel()
      return
    }
    if (props.closeOnEsc && isTopmost(modalId)) {
      requestClose()
    }
    return
  }

  if (e.key !== 'Tab') return

  const activeId = confirmVisible.value ? confirmModalId : modalId
  if (!isTopmost(activeId)) return

  const modalEl = getModalElement(activeId)
  if (!modalEl) return

  const focusableSelectors = [
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'a[href]',
    '[tabindex]:not([tabindex="-1"])'
  ].join(', ')

  const focusableElements = Array.from(modalEl.querySelectorAll<HTMLElement>(focusableSelectors))
  if (focusableElements.length === 0) {
    e.preventDefault()
    return
  }

  const first = focusableElements[0]
  const last = focusableElements[focusableElements.length - 1]
  const active = document.activeElement as HTMLElement | null

  if (!active || !modalEl.contains(active)) {
    first.focus()
    e.preventDefault()
    return
  }

  if (e.shiftKey && active === first) {
    last.focus()
    e.preventDefault()
  } else if (!e.shiftKey && active === last) {
    first.focus()
    e.preventDefault()
  }
}

// Auto-focus when modal opens
const setAutoFocus = (id: string) => {
  const currentModal = getModalElement(id)
  if (!currentModal) return

  const focusableSelectors = [
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'a[href]',
    '[tabindex]:not([tabindex="-1"])'
  ].join(', ')

  const focusableElements = currentModal.querySelectorAll(focusableSelectors)

  if (focusableElements.length === 0) {
    const closeButton = currentModal.querySelector('.modal-header .btn-close') as HTMLElement
    if (closeButton) {
      closeButton.focus()
    }
    return
  }

  const autofocusElements = Array.from(focusableElements).filter(el =>
      el.hasAttribute('data-autofocus')
  )

  if (autofocusElements.length > 0) {
    (autofocusElements[0] as HTMLElement).focus()
  } else {
    (focusableElements[0] as HTMLElement).focus()
  }
}

watch(
    () => visible.value,
    (val: boolean) => {
      if (val) {
        pushStack(modalId)
        updateBodyScrollLock()
        document.addEventListener('keydown', onKeydown)
        setTimeout(() => setAutoFocus(modalId), 0)
      } else {
        removeStack(modalId)
        updateBodyScrollLock()
        document.removeEventListener('keydown', onKeydown)
        confirmVisible.value = false
      }
    },
    { immediate: true }
)

watch(
    () => confirmVisible.value,
    (val: boolean) => {
      if (val) {
        pushStack(confirmModalId)
        updateBodyScrollLock()
        document.addEventListener('keydown', onKeydown)
        setTimeout(() => setAutoFocus(confirmModalId), 0)
      } else {
        removeStack(confirmModalId)
        updateBodyScrollLock()
      }
    }
)

onBeforeUnmount(() => {
  removeStack(modalId)
  removeStack(confirmModalId)
  updateBodyScrollLock()
  document.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
/*
  Backdrop: background + blur. z-index comes from Bootstrap .modal and inline modalStyle/confirmStyle.
*/
.modal {
  background-color: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(2px);
}

/* When modal is active, .show class is added */
/* display: block appears via inline style in template */

/* Modal content (Bootstrap standard) */
/* Here you can leave everything as is - Bootstrap will set needed sizes */
/* .modal-content, .modal-header, .modal-body, .modal-footer - all pulled from Bootstrap CSS */

/* body.modal-open { overflow: hidden } — provided by Bootstrap CSS when we add the class */
</style>
