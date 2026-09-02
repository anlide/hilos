<!-- HilosActionError — the refusal of a tracked action, drawn where the person
acted. The "server refused" plate of the modal mockup: an alert with an icon and
the sentence, at the top of the modal body and above the fields, where a toast
alone would fly away from the form it belongs to (toasts.md). An admin surface
additionally gets the two fields the backend only sends there — the class name of
what actually failed, as a badge, and its original text behind that badge in a
detail panel with a Copy button (HIL-779). Their presence is the sign the
framework held something back: a refusal written for a person is already shown in
full, so it carries no detail. The panel is a HilosModal over the modal the
action was sent from; stacking is the teleport DOM order, not a hand-set z-index.
Bootstrap classes only, save the one pre-wrap declaration the mockup calls for
because Bootstrap has no utility for it (styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { copyToClipboard, isClipboardAvailable } from '@hilos/core'

import HilosModal from './HilosModal.vue'
import type { TrackedAction } from './useTrackedAction.js'

const props = defineProps<{
  /** The tracked action whose latest failure this draws; nothing renders while it is clear. */
  action: TrackedAction
}>()

/** The type badge, in both the clickable and the inert form. */
const BADGE_CLASS =
  'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle d-inline-flex align-items-center gap-1 flex-shrink-0'

const detailOpen = ref(false)
const canCopy = isClipboardAvailable()
const errorType = computed(() => props.action.failure.value?.errorType)
const errorDetail = computed(() => props.action.failure.value?.errorDetail)
// An empty message leaves nothing to open the panel with, and the type is then
// all that is known — so the badge is still drawn, just not as a button.
const hasDetail = computed(() => (errorDetail.value ?? '') !== '')

// Clearing the failure takes the panel with it: the screen re-arms on the next
// attempt, and a panel left open would be showing the previous one's text.
watch(
  () => props.action.failure.value,
  (failure) => {
    if (failure === null) {
      detailOpen.value = false
    }
  },
)

function copyDetail(): void {
  void copyToClipboard(errorDetail.value ?? '')
}
</script>

<template>
  <div
    v-if="action.error"
    class="alert alert-danger d-flex align-items-center gap-2 py-2"
    role="alert"
    data-id="hilos-action-error"
  >
    <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
    <span class="flex-grow-1">{{ action.error }}</span>
    <button
      v-if="errorType && hasDetail"
      type="button"
      :class="BADGE_CLASS"
      :title="`Show the original ${errorType} message`"
      data-id="hilos-action-error-type"
      @click="detailOpen = true"
    >
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>{{ errorType }}</span>
    </button>
    <span
      v-else-if="errorType"
      :class="BADGE_CLASS"
      data-id="hilos-action-error-type"
    >
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>{{ errorType }}</span>
    </span>
  </div>

  <HilosModal v-model="detailOpen" :title="errorType">
    <pre
      class="mb-0 small text-break"
      style="white-space: pre-wrap; max-height: 60vh; overflow-y: auto"
      data-id="hilos-action-error-detail"
      >{{ errorDetail }}</pre
    >
    <template #actions="{ requestClose }">
      <button
        v-if="canCopy"
        type="button"
        class="btn btn-outline-secondary"
        data-id="hilos-action-error-copy"
        @click="copyDetail"
      >
        <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy
      </button>
      <button
        type="button"
        class="btn btn-secondary"
        data-id="hilos-action-error-close"
        @click="requestClose"
      >
        Close
      </button>
    </template>
  </HilosModal>
</template>
