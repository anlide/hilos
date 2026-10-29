<!-- Keep the primitive mounted when the source reason clears: an open explanation
belongs to its reader until they close it. Only the native opener follows the
current reason; HilosModal owns focus, closing, and unmount cleanup. A reason the
server hid (Hideable) still gives the opener, but its words reach neither the
title nor a string: the dialog draws the mark through HilosHideable. -->
<script setup lang="ts">
import { type Hideable, isHiddenValue } from '@hilos/core/base'
import { ref } from 'vue'

import HilosHideable from './HilosHideable.vue'
import HilosModal from './HilosModal.vue'

const props = withDefaults(
  defineProps<{
    /** The current refusal, or its hidden mark; null hides only the opener. */
    reason: Hideable<string | null>
    /** Accessible name of the native explanation button. */
    label?: string
    /** Visible and accessible name of the explanation dialog. */
    title?: string
  }>(),
  {
    label: 'Why this action is unavailable',
    title: 'Action unavailable',
  },
)

const open = ref(false)
const explanation = ref<Hideable<string>>('')

/** Read the current reason once, for this visit to the dialog. */
function showReason(): void {
  if (props.reason === null) return
  explanation.value = props.reason
  open.value = true
}
</script>

<template>
  <button
    v-if="reason !== null"
    type="button"
    class="btn btn-sm btn-outline-secondary"
    :title="isHiddenValue(reason) ? undefined : reason"
    :aria-label="label"
    data-id="hilos-action-reason"
    @click="showReason"
  >
    <i class="bi bi-question-circle" aria-hidden="true"></i>
  </button>
  <HilosModal v-model="open" :title="title" initial-focus="dialog">
    <p class="mb-0 text-break" data-id="hilos-action-reason-body">
      <HilosHideable :value="explanation" />
    </p>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        data-id="hilos-action-reason-close"
        @click="requestClose"
      >
        Close
      </button>
    </template>
  </HilosModal>
</template>
