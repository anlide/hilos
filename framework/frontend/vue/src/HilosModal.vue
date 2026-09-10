<!-- HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
fills #header (defaults to the title), the body (default slot), and #actions
(which receives `requestClose` so a footer button closes through the confirm
guard). The footer exists exactly when #actions is declared or a copyText is
given: a dialog with neither gets no footer element — neither buttons nor the
bordered strip — and there is no default footer to opt out of.
The body is what scrolls, always: the dialog is never taller than the window
(modal-dialog-scrollable), the header and the footer stay put, and a short
dialog is not changed by it at all. On a narrow screen the dialog becomes a
sheet at the bottom edge and its buttons a full-width column, main action on
top — inside the modal, so no surface that opens one is touched.
Copy is the modal's own button: pass `copyText` and it draws one first in the
footer, because a long technical text almost always has to be carried somewhere
else, and the rule for showing such a text belongs here rather than to every
page that has one (docs/agents/frontend/rules-and-violations.md, section E).
Open state is v-model (`v-model="open"`); the dialog
teleports to <body>, traps Tab focus and returns focus to the opener on close,
and is keyboard- and ARIA-labelled (a11y ships in v1, styling-rules.md). With
confirmOnClose, an Esc/backdrop/close attempt raises an inline confirm step
instead of discarding a dirty draft. The confirm-step state machine is the core
modal controller and the focus trap / scroll lock are core/dom; this view only
renders and wires events. Bootstrap classes only, save for the one declaration
the Sass layer names — the bottom sheet, which stock Bootstrap has nothing for;
stacking is the teleport DOM order, not a hand-set z-index. -->
<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import {
  FocusTrap,
  copyToClipboard,
  createModalController,
  isClipboardAvailable,
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
    /**
     * The dialog's accessible name when it shows no title of its own — a
     * surface whose heading changes with the step owns that heading in the
     * body, and the name of the dialog still has to say what it is for.
     * Ignored when `title` is set: a visible title names the dialog already.
     */
    ariaLabel?: string
    /**
     * The id of the node that carries the dialog's name, when that name is
     * written somewhere else — the heading a surface draws in the body. The
     * accessible name is then the very text a sighted person reads, and it
     * follows that text when it changes. Ignored when `title` is set, for the
     * same reason `ariaLabel` is. Given together with `ariaLabel`, this wins
     * while the node exists, and `ariaLabel` stays as the fallback name for a
     * surface that carries no such heading.
     */
    ariaLabelledby?: string
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
    /**
     * The text the footer's Copy button writes to the clipboard. Empty means no
     * such button — and so does a document with no clipboard at all (plain
     * http), because a button that silently does nothing is worse than none.
     */
    copyText?: string
  }>(),
  {
    title: '',
    ariaLabel: '',
    ariaLabelledby: '',
    closeOnEsc: true,
    closeOnBackdrop: true,
    confirmOnClose: false,
    confirmTitle: 'Discard changes?',
    confirmMessage: 'You have unsaved changes. Discard them?',
    confirmOkText: 'Discard',
    confirmCancelText: 'Keep editing',
    copyText: '',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
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

const copied = ref(false)
const showCopy = computed(() => props.copyText !== '' && isClipboardAvailable())

async function onCopy(): Promise<void> {
  copied.value = await copyToClipboard(props.copyText)
}

function activeRoot(): HTMLElement | undefined {
  return confirmVisible.value ? confirmDialog.value : dialog.value
}

watch(
  () => props.modelValue,
  (open) => {
    modal.reset()
    // The label is the only thing the button says, so it starts over with the
    // dialog: a reopened modal reporting "Copied" is reporting the last visit.
    copied.value = false
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
        :aria-label="title || ariaLabel || undefined"
        :aria-labelledby="!title && ariaLabelledby ? ariaLabelledby : undefined"
        data-id="modal"
        @keydown.esc.prevent="modal.onEsc()"
        @keydown.tab="onTab"
        @click.self="modal.onBackdrop()"
      >
        <div
          class="modal-dialog modal-dialog-centered modal-dialog-scrollable hilos-modal-sheet"
        >
          <div class="modal-content">
            <div class="modal-header">
              <slot name="header">
                <!-- No title, no heading: an empty one is a heading in the
                accessibility tree that names nothing, and a dialog whose
                heading lives in its body (the auth surface) has a title here
                only by accident. -->
                <h5 v-if="title" class="modal-title mb-0">{{ title }}</h5>
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
            <div
              v-if="$slots.actions || showCopy"
              class="modal-footer flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center"
            >
              <!-- Copy comes first in the markup so the reversed column on a
              narrow screen puts it under Close, the main action on top. -->
              <button
                v-if="showCopy"
                type="button"
                class="btn btn-outline-secondary"
                data-id="modal-copy"
                @click="onCopy"
              >
                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                {{ copied ? 'Copied' : 'Copy' }}
              </button>
              <slot name="actions" :request-close="modal.requestClose" />
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
        <div
          class="modal-dialog modal-dialog-centered modal-dialog-scrollable hilos-modal-sheet"
        >
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title mb-0">{{ confirmTitle }}</h5>
            </div>
            <div class="modal-body">
              <p class="mb-0">{{ confirmMessage }}</p>
            </div>
            <div
              class="modal-footer flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center"
            >
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
