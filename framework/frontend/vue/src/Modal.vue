<!-- Modal — the one home for editing (edit-in-modal is a hard Hilos rule;
docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
fills #header (defaults to the title), the body (default slot), and #actions
(defaults to Cancel/OK, and receives `requestClose` so a custom footer can close
through the confirm guard). Open state is v-model (`v-model="open"`); the dialog
teleports to <body>, traps Tab focus and returns focus to the opener on close,
and is keyboard- and ARIA-labelled (a11y ships in v1, styling-rules.md). With
confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm step
instead of discarding a dirty draft. Bootstrap classes only — no CSS of its
own; stacking is the teleport DOM order, not a hand-set z-index. -->
<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

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
const confirmVisible = ref(false)
// The element focus returns to when the dialog closes (focus-return, a11y).
let opener: HTMLElement | null = null

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(root: HTMLElement): HTMLElement[] {
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE))
}

function activeRoot(): HTMLElement | undefined {
  return confirmVisible.value ? confirmDialog.value : dialog.value
}

function focusInitial(): void {
  const root = activeRoot()
  if (!root) {
    return
  }
  const autofocus = root.querySelector<HTMLElement>('[data-autofocus]')
  ;(autofocus ?? focusables(root)[0] ?? root).focus()
}

watch(
  () => props.modelValue,
  (open) => {
    confirmVisible.value = false
    if (open) {
      opener =
        document.activeElement instanceof HTMLElement
          ? document.activeElement
          : null
      document.body.classList.add('modal-open')
      void nextTick(focusInitial)
    } else {
      document.body.classList.remove('modal-open')
      opener?.focus()
      opener = null
    }
  },
  { immediate: true },
)

// Moving in and out of the confirm step keeps focus inside the visible dialog.
watch(confirmVisible, () => {
  void nextTick(focusInitial)
})

function close(): void {
  emit('update:modelValue', false)
  emit('cancel')
}

function onOk(): void {
  emit('ok')
}

function requestClose(): void {
  if (props.confirmOnClose) {
    confirmVisible.value = true
    return
  }
  close()
}

function onEsc(): void {
  if (confirmVisible.value) {
    confirmVisible.value = false
    return
  }
  if (props.closeOnEsc) {
    requestClose()
  }
}

function onBackdrop(): void {
  if (confirmVisible.value || !props.closeOnBackdrop) {
    return
  }
  requestClose()
}

function onTab(event: KeyboardEvent): void {
  const root = activeRoot()
  if (!root) {
    return
  }
  const items = focusables(root)
  if (items.length === 0) {
    event.preventDefault()

    return
  }
  const first = items[0]
  const last = items[items.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function discard(): void {
  confirmVisible.value = false
  close()
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
        @keydown.esc.prevent="onEsc"
        @keydown.tab="onTab"
        @click.self="onBackdrop"
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
                @click="requestClose"
              ></button>
            </div>
            <div class="modal-body">
              <slot />
            </div>
            <div class="modal-footer">
              <slot name="actions" :request-close="requestClose">
                <button
                  type="button"
                  class="btn btn-secondary"
                  @click="requestClose"
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
        @keydown.esc.prevent="onEsc"
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
                @click="confirmVisible = false"
              >
                {{ confirmCancelText }}
              </button>
              <button
                type="button"
                class="btn btn-danger"
                data-id="modal-confirm-discard"
                @click="discard"
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
