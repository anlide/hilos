<!-- HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
fills #header (defaults to the title), the body (default slot), and #actions
(defaults to Cancel/OK, and receives `requestClose` so a custom footer can close
through the confirm guard). Open state is v-model (`v-model="open"`); the dialog
teleports to <body>, traps Tab focus and returns focus to the opener on close,
and is keyboard- and ARIA-labelled (a11y ships in v1, styling-rules.md). With
confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm step
instead of discarding a dirty draft. The confirm-step state machine is the core
modal controller and the focus trap / scroll lock are core/dom; this view only
renders and wires events. Bootstrap classes only — no CSS of its own; stacking is
the teleport DOM order, not a hand-set z-index. -->
<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import {
  FocusTrap,
  createModalController,
  lockBodyScroll,
  unlockBodyScroll,
} from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** Whether the dialog is open (v-model). */
    modelValue: boolean
    /** The default header title (overridable via the #header slot). */
    title?: string
    /** Close on the Escape key (through the confirm guard). */
    closeOnEsc?: boolean
    /** Close on a backdrop click (through the confirm guard). */
    closeOnBackdrop?: boolean
    /** Raise a confirm step before closing — set it when the draft is dirty. */
    confirmOnClose?: boolean
    /** Confirm-step heading. */
    confirmTitle?: string
    /** Confirm-step body. */
    confirmMessage?: string
    /** Confirm-step discard label. */
    confirmOkText?: string
    /** Confirm-step keep-editing label. */
    confirmCancelText?: string
  }>(),
  {
    title: '',
    closeOnEsc: true,
    closeOnBackdrop: true,
    confirmOnClose: false,
    confirmTitle: 'Discard changes?',
    confirmMessage: 'You have unsaved changes. Discard them?',
    confirmOkText: 'Discard',
    confirmCancelText: 'Keep editing',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  ok: []
  cancel: []
}>()

const dialog = ref<HTMLElement>()
const confirmDialog = ref<HTMLElement>()
const trap = new FocusTrap()

const modal = createModalController({
  confirmOnClose: () => props.confirmOnClose,
  closeOnEsc: () => props.closeOnEsc,
  closeOnBackdrop: () => props.closeOnBackdrop,
  onClose: () => {
    emit('update:modelValue', false)
    emit('cancel')
  },
})
const confirmVisible = useSignal(modal.confirmVisible)

function activeRoot(): HTMLElement | undefined {
  return confirmVisible.value ? confirmDialog.value : dialog.value
}

watch(
  () => props.modelValue,
  (open) => {
    modal.reset()
    if (open) {
      lockBodyScroll(document)
      void nextTick(() => {
        const root = activeRoot()
        if (root) {
          trap.activate(root)
        }
      })
    } else {
      unlockBodyScroll(document)
      trap.release()
    }
  },
  { immediate: true },
)

// Moving in and out of the confirm step keeps focus inside the visible dialog.
watch(confirmVisible, () => {
  void nextTick(() => {
    const root = activeRoot()
    if (root) {
      trap.refocus(root)
    }
  })
})

function onTab(event: KeyboardEvent): void {
  const root = activeRoot()
  if (root) {
    trap.handleTab(root, event)
  }
}

function onOk(): void {
  emit('ok')
}
</script>

<template>
  <teleport to="body">
    <template v-if="modelValue">
      <div class="modal-backdrop fade show"></div>
      <div
        ref="dialog"
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        :aria-label="title || undefined"
        data-id="modal"
        @keydown.esc.prevent="modal.onEsc()"
        @keydown.tab="onTab"
        @click.self="modal.onBackdrop()"
      >
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <slot name="header">
                <h5 class="modal-title mb-0">{{ title }}</h5>
              </slot>
              <button
                type="button"
                class="btn-close"
                aria-label="Close"
                data-id="modal-close"
                @click="modal.requestClose()"
              ></button>
            </div>
            <div class="modal-body">
              <slot />
            </div>
            <div class="modal-footer">
              <slot name="actions" :request-close="modal.requestClose">
                <button
                  type="button"
                  class="btn btn-secondary"
                  @click="modal.requestClose()"
                >
                  Cancel
                </button>
                <button type="button" class="btn btn-primary" @click="onOk">
                  OK
                </button>
              </slot>
            </div>
          </div>
        </div>
      </div>
      <div
        v-if="confirmVisible"
        ref="confirmDialog"
        class="modal fade show d-block"
        tabindex="-1"
        role="alertdialog"
        aria-modal="true"
        :aria-label="confirmTitle"
        data-id="modal-confirm"
        @keydown.esc.prevent="modal.onEsc()"
        @keydown.tab="onTab"
      >
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title mb-0">{{ confirmTitle }}</h5>
            </div>
            <div class="modal-body">
              <p class="mb-0">{{ confirmMessage }}</p>
            </div>
            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-secondary"
                data-id="modal-confirm-cancel"
                @click="modal.keepEditing()"
              >
                {{ confirmCancelText }}
              </button>
              <button
                type="button"
                class="btn btn-danger"
                data-id="modal-confirm-discard"
                @click="modal.discard()"
              >
                {{ confirmOkText }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>
  </teleport>
</template>
