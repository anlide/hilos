<!-- HilosModal — the one home for editing (edit-in-modal is a hard Hilos rule;
docs/agents/frontend/conflict-resolution.md). A slot-first dialog: the parent
fills #header (defaults to the title), the body (default slot, or #body), and
#actions (which receives `requestClose` so a footer button closes through the
confirm guard). The footer exists exactly when #actions is declared or a
copyText is given: a dialog with neither gets no footer element — neither
buttons nor the bordered strip — and there is no default footer to opt out of.
While the dialog's request is in flight (`busy`) nothing closes it and its body
is locked: the default slot stands inside a fieldset with no border or spacing
that is always there and that `busy` disables, so every field in it turns
natively disabled and keeps what was typed — the same nodes, never remounted.
A body whose own submitting LoadingButton stands inside it fills #body instead:
it receives the same `busy`, replaces only that fieldset inside the same
modal-body, and its owner disables its fields with it — owning the slot is no
license to leave them editable. The footer is not wrapped: Cancel and the other
buttons of #actions are disabled by their owner with the same `busy`, Copy by
the modal, and a LoadingButton stays locked by its own `loading`. Nothing turns
inert or aria-hidden, so the text, the draft and the refusal regions stay
readable. When `busy` clears, focus left on the dialog itself — because the
step that moved in flight found its field locked — or on the page's body goes
to the initial element again, as on opening; any other element keeps it.
The shell connection lock is independent of busy: permanent fieldsets cover
the whole dialog and its confirm layer, including custom slots, and core binds
the real roots without restarting the focus trap or scroll lock.
The body is what scrolls, always: the dialog is never taller than the window
(modal-dialog-scrollable), the header and the footer stay put, and a short
dialog is not changed by it at all. On a narrow screen the dialog becomes a
sheet at the bottom edge and its buttons one row sharing it equally, the main
action last, on the right — inside the modal, so no surface that opens one is
touched.
The dialog stays narrow by default; `size='wide'` applies Bootstrap's modal-lg
when the opening surface knows its content is a table or an analysis
(mockups/components/modal, the Sizes node).
Copy is the modal's own button: pass `copyText` and it draws one first in the
footer, because a long technical text almost always has to be carried somewhere
else, and the rule for showing such a text belongs here rather than to every
page that has one (docs/agents/frontend/rules-and-violations.md, section E).
Open state is v-model (`v-model="open"`); the dialog
teleports to <body>, traps Tab focus and returns focus to the opener on close,
and is keyboard- and ARIA-labelled (a11y ships in v1, styling-rules.md).
With confirmOnClose, an Esc/backdrop/close attempt raises a confirm step
standing as a second layer over the modal — its own backdrop and one step
narrower (mockups/components/modal, D-106) — learning its depth from the core
modal stack with its own entry, instead of discarding a dirty draft. The
confirm-step state machine is the core modal controller and the focus trap /
scroll lock are core/dom; this view only renders and wires events. Bootstrap
classes only, save for the declarations the Sass layer names — the bottom
sheet, which stock Bootstrap has nothing for, and the modal layer. A modal
opened over a modal learns its depth from the core modal stack by itself, with
nothing passed by the surface that opens it, and hands the number to the Sass
layer through `--hilos-modal-depth`: each layer dims everything under it and
stands one step narrower (mockups/components/modal, the node for a modal over
a modal). -->
<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, useId, watch } from 'vue'
import {
  CONNECTION_INTERACTION_COPY,
  FocusTrap,
  bindInteractionBlock,
  copyToClipboard,
  createModalController,
  enterModalLayer,
  isClipboardAvailable,
  leaveModalLayer,
  lockBodyScroll,
  type FocusPlacement,
  type ModalLayerOwner,
  type ScrollLockOwner,
  unlockBodyScroll,
} from '@hilos/core/base'

import { useConnectionInteraction } from './hilosConnectionInteraction.js'
import { useSignal } from './useSignal.js'

/**
 * The trap placement the prop maps onto: `'dialog'` when there is nothing to
 * fill, `'marked'` for a mark in this file or in the body another component
 * draws.
 *
 * @param initialFocus The modal's `initialFocus` prop.
 * @returns The placement handed to {@link FocusTrap.activate}.
 */
function trapPlacement(initialFocus: '' | 'dialog' | 'inner'): FocusPlacement {
  return initialFocus === 'dialog' ? 'dialog' : 'marked'
}

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
    /**
     * Where focus lands when the dialog opens. Empty (the default) means a
     * `[data-autofocus]` mark lives in this file; `'dialog'` lands on the
     * dialog itself when there is nothing to fill; `'inner'` means the body
     * is drawn by another component and the mark lives there.
     */
    initialFocus?: '' | 'dialog' | 'inner'
    /**
     * The width the content asks for. Empty keeps the narrow default; `'wide'`
     * applies the mockup's wide size for a table or an analysis. The opening
     * surface owns this choice because it knows what the dialog contains.
     */
    size?: '' | 'wide'
    /** Close on the Escape key (through the confirm guard). */
    closeOnEsc?: boolean
    /** Close on a backdrop click (through the confirm guard). */
    closeOnBackdrop?: boolean
    /**
     * The dialog's request is in flight: the header close button and the
     * footer's Copy turn natively disabled, and neither Escape, a backdrop
     * click, nor the `requestClose` the #actions slot receives closes the
     * dialog or raises the confirm step. Outranks `closeOnEsc` and
     * `closeOnBackdrop`; a gesture refused while busy is not replayed once it
     * clears. The parent setting `modelValue` to false still closes the
     * dialog. The body is locked too: the fieldset around the default slot is
     * disabled, or #body receives the flag to lock its own fields. A control
     * disabled for a reason of its own stays disabled once it clears. Once it
     * clears, focus that stands on the dialog itself or on the page's body goes
     * to the initial element again, as on opening; any other element keeps it —
     * a control in the dialog, or one outside it, such as another modal
     * standing over this one.
     */
    busy?: boolean
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
    initialFocus: '',
    size: '',
    closeOnEsc: true,
    closeOnBackdrop: true,
    busy: false,
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
const interactionScope = ref<HTMLFieldSetElement>()
const confirmInteractionScope = ref<HTMLFieldSetElement>()
const interaction = useConnectionInteraction()
const interactionBlocked = useSignal(interaction.blocked)
const connectionDescriptionId = `hilos-modal-connection-${useId()}`
const confirmConnectionDescriptionId = `${connectionDescriptionId}-confirm`
const trap = new FocusTrap()
let disposed = false
let activation = 0
const scrollLockOwner: ScrollLockOwner = {}
const modalLayerOwner: ModalLayerOwner = {}
// The layer this modal stands on: 0 over the page, 1 over another modal.
const layerDepth = ref(0)
const confirmLayerOwner: ModalLayerOwner = {}
// The layer the confirm step stands on: one above its modal while nothing stands over it.
const confirmDepth = ref(0)

const modal = createModalController({
  confirmOnClose: () => props.confirmOnClose,
  closeOnEsc: () => props.closeOnEsc,
  closeOnBackdrop: () => props.closeOnBackdrop,
  busy: () => props.busy,
  interactionBlocked: () => interaction.blocked.get(),
  onClose: () => {
    emit('update:modelValue', false)
    emit('cancel')
  },
})
const confirmVisible = useSignal(modal.confirmVisible)

watch(
  [dialog, interactionScope],
  ([root, fieldset], _previous, onCleanup) => {
    if (!root || !fieldset) return
    onCleanup(
      bindInteractionBlock(root, {
        fieldset,
        blocked: interaction.blocked,
        descriptionId: connectionDescriptionId,
      }),
    )
  },
  { flush: 'post' },
)
watch(
  [confirmDialog, confirmInteractionScope],
  ([root, fieldset], _previous, onCleanup) => {
    if (!root || !fieldset) return
    onCleanup(
      bindInteractionBlock(root, {
        fieldset,
        blocked: interaction.blocked,
        descriptionId: confirmConnectionDescriptionId,
      }),
    )
  },
  { flush: 'post' },
)

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
    const generation = ++activation
    modal.reset()
    // The label is the only thing the button says, so it starts over with the
    // dialog: a reopened modal reporting "Copied" is reporting the last visit.
    copied.value = false
    if (open) {
      lockBodyScroll(document, scrollLockOwner)
      layerDepth.value = enterModalLayer(document, modalLayerOwner)
      void nextTick(() => {
        if (disposed || generation !== activation || !props.modelValue) return
        const root = activeRoot()
        if (root?.isConnected) {
          trap.activate(root, trapPlacement(props.initialFocus))
        }
      })
    } else {
      unlockBodyScroll(scrollLockOwner)
      leaveModalLayer(modalLayerOwner)
      leaveModalLayer(confirmLayerOwner)
      trap.release()
    }
  },
  { immediate: true },
)

onUnmounted(() => {
  disposed = true
  activation++
  trap.release()
  unlockBodyScroll(scrollLockOwner)
  leaveModalLayer(modalLayerOwner)
  leaveModalLayer(confirmLayerOwner)
})

// Moving in and out of the confirm step keeps focus inside the visible dialog.
watch(confirmVisible, (visible) => {
  if (visible && props.modelValue) {
    confirmDepth.value = enterModalLayer(document, confirmLayerOwner)
  } else {
    leaveModalLayer(confirmLayerOwner)
  }
  void nextTick(() => {
    if (disposed || !props.modelValue) return
    const root = activeRoot()
    if (root?.isConnected) {
      trap.refocus(
        root,
        confirmVisible.value ? 'dialog' : trapPlacement(props.initialFocus),
      )
    }
  })
})

// A step that moved while the request was out found its field locked and put
// focus on the dialog itself; once busy clears, focus that stands on the dialog
// itself or on the page's body goes to the initial element again, as on
// opening. Any other element keeps it — a control in the dialog, another modal
// standing over this one, an open confirm step.
watch(
  () => props.busy,
  (busy, wasBusy) => {
    if (busy || !wasBusy) return
    void nextTick(() => {
      if (disposed || props.busy || !props.modelValue || confirmVisible.value) {
        return
      }
      const root = dialog.value
      if (!root?.isConnected) return
      const { activeElement: active, body } = root.ownerDocument
      if (active && active !== root && active !== body) return
      trap.refocus(root, trapPlacement(props.initialFocus))
    })
  },
)

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
      <div
        class="modal-backdrop fade show hilos-modal-layer"
        :style="{ '--hilos-modal-depth': layerDepth }"
      ></div>
      <div
        ref="dialog"
        class="modal fade show d-block hilos-modal-layer"
        :style="{ '--hilos-modal-depth': layerDepth }"
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
          :class="{ 'modal-lg': size === 'wide' }"
        >
          <fieldset
            ref="interactionScope"
            class="modal-content m-0 p-0"
            data-id="modal-interaction-scope"
          >
            <span
              :id="connectionDescriptionId"
              class="visually-hidden"
              data-id="modal-connection-description"
              :hidden="!interactionBlocked"
              >{{ CONNECTION_INTERACTION_COPY.unavailable }}</span
            >
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
                :disabled="busy"
                @click="modal.requestClose()"
              ></button>
            </div>
            <div class="modal-body">
              <!-- The fieldset is always there, so flipping busy only toggles
              its disabled: the fields and their draft are never remounted. -->
              <slot name="body" :busy="busy">
                <fieldset class="m-0 p-0 border-0" :disabled="busy">
                  <slot />
                </fieldset>
              </slot>
            </div>
            <div
              v-if="$slots.actions || showCopy"
              class="modal-footer hilos-button-row"
            >
              <!-- Copy comes first in the markup so the main action stands on
              the right, last in the row. -->
              <button
                v-if="showCopy"
                type="button"
                class="btn btn-outline-secondary"
                data-id="modal-copy"
                :disabled="busy"
                @click="onCopy"
              >
                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                {{ copied ? 'Copied' : 'Copy' }}
              </button>
              <slot name="actions" :request-close="modal.requestClose" />
            </div>
          </fieldset>
        </div>
      </div>
      <template v-if="confirmVisible">
        <div
          class="modal-backdrop fade show hilos-modal-layer"
          :style="{ '--hilos-modal-depth': confirmDepth }"
        ></div>
        <div
          ref="confirmDialog"
          class="modal fade show d-block hilos-modal-layer"
          :style="{ '--hilos-modal-depth': confirmDepth }"
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
            <fieldset
              ref="confirmInteractionScope"
              class="modal-content m-0 p-0"
              data-id="modal-confirm-interaction-scope"
            >
              <span
                :id="confirmConnectionDescriptionId"
                class="visually-hidden"
                data-id="modal-connection-description"
                :hidden="!interactionBlocked"
                >{{ CONNECTION_INTERACTION_COPY.unavailable }}</span
              >
              <div class="modal-header">
                <h5 class="modal-title mb-0">{{ confirmTitle }}</h5>
              </div>
              <div class="modal-body">
                <p class="mb-0">{{ confirmMessage }}</p>
              </div>
              <div class="modal-footer hilos-button-row">
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
            </fieldset>
          </div>
        </div>
      </template>
    </template>
  </teleport>
</template>
