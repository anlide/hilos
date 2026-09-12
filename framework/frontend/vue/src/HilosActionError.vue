<!-- HilosActionError — the refusal of a tracked action, drawn where the person
acted. The "server refused" plate of the modal mockup: an alert with an icon and
the sentence, at the top of the modal body and above the fields, where a toast
alone would fly away from the form it belongs to (toasts.md).
Two layers, and they never collapse into one. The slot is permanent and carries
no styling class at all: it is the live region — a node carrying aria-live that
is inserted together with its own text announces nothing (accessibility.md) —
and while there is nothing to say it looks like nothing. Inside it stands either
the visible plate, which carries no role of its own or the reader says the same
sentence twice, or an invisible twin of that very plate. The twin is what holds
the room, so what stands below the plate never jumps when a refusal arrives; it
is the plate itself made `invisible` rather than a guessed `min-height`, because
it is then exactly as tall as the real thing whatever the real thing becomes
(LoadingButton.vue holds the room for the text under its spinner the same way),
and a declaration of our own outside the Sass layer is not ours to write
(styling-rules.md).
The plate is one line at any length: the sentence truncates and is read whole in
the detail modal behind the button on the right. That button is there for every
refusal, because it is the only way to the full text; on an admin surface it
also carries the class name of what actually failed, which is the sign the
framework held something back (HIL-779). The name hides on a narrow screen and
the icon does not: the name is longer than the narrowest plate and would eat the
sentence the plate exists for. Inside the modal the sentence comes first and the
original text below it, both drawn by HilosLongText and copied by the modal's own
copyText, so neither the wrapping of a long line nor the Copy button is written
here a second time (rules-and-violations.md, section E).
`suppressed` says "this same action is answering somewhere else right now": the
room stays, the voice goes. It has to be the room that stays — a page that drops
the plate to keep it out of its own confirmation moves everything under the
backdrop and hands it back shifted.
Bootstrap classes only. -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import HilosLongText from './HilosLongText.vue'
import HilosModal from './HilosModal.vue'
import type { TrackedAction } from './useTrackedAction.js'

const props = withDefaults(
  defineProps<{
    /** The tracked action whose latest failure this draws; the room is held either way. */
    action: TrackedAction
    /** Hold the room and stay silent — this action is answering somewhere else. */
    suppressed?: boolean
  }>(),
  { suppressed: false },
)

/** The details button, and the inert copy of it the twin holds the room for. */
const BADGE_CLASS =
  'badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle d-inline-flex align-items-center gap-1 flex-shrink-0'

/** The classes the plate and its invisible twin share, to the character. */
const PLATE_CLASS = 'alert alert-danger d-flex align-items-center gap-2 py-2'

const detailOpen = ref(false)
// The message is read through a computed and never as `action.error` in the
// template: it is a ref sitting on a plain object, and Vue unwraps no such ref
// in a template expression — `v-if="action.error"` is true even with no message
// at all, which is how an empty red plate reached a live screen
// (docs/agents/code-style/vue-template-refs.md).
const message = computed(() => props.action.error.value)
const errorType = computed(() => props.action.failure.value?.errorType)
const errorDetail = computed(() => props.action.failure.value?.errorDetail)
// An original text the backend did not send is not a shorter panel but no
// panel: what is drawn then is the sentence alone.
const hasDetail = computed(() => (errorDetail.value ?? '') !== '')
// Copy carries the original text when there is one and the sentence when there
// is not: it is copied to be pasted into a ticket, and what is pasted is the
// long half.
const copyText = computed(() =>
  hasDetail.value ? (errorDetail.value ?? '') : (message.value ?? ''),
)

// Clearing the message takes the panel with it: the screen re-arms on the next
// attempt, and a panel left open would be showing the previous one's text. The
// guard watches the message and not the failure, because the failure is null
// for everything thrown as something other than an ActionError — and the panel
// now opens for those too.
watch(message, (next) => {
  if (next === null) {
    detailOpen.value = false
  }
})
</script>

<template>
  <div data-id="hilos-action-error-slot" role="alert" aria-live="assertive">
    <div
      v-if="message !== null && !suppressed"
      :class="PLATE_CLASS"
      data-id="hilos-action-error"
    >
      <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">{{ message }}</span>
      <button
        type="button"
        :class="BADGE_CLASS"
        aria-label="Show error details"
        title="Show error details"
        data-id="hilos-action-error-details"
        @click="detailOpen = true"
      >
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span
          v-if="errorType"
          class="d-none d-sm-inline"
          data-id="hilos-action-error-type"
          >{{ errorType }}</span
        >
      </button>
    </div>
    <div
      v-else
      :class="[PLATE_CLASS, 'invisible']"
      aria-hidden="true"
      data-id="hilos-action-error-idle"
    >
      <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">&nbsp;</span>
      <span :class="BADGE_CLASS">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
      </span>
    </div>
  </div>

  <HilosModal
    v-model="detailOpen"
    :title="errorType ?? 'Error details'"
    :copy-text="copyText"
  >
    <div class="d-flex flex-column gap-3">
      <HilosLongText
        kind="prose"
        :text="message ?? ''"
        data-id="hilos-action-error-message"
      />
      <HilosLongText
        v-if="hasDetail"
        kind="output"
        :text="errorDetail ?? ''"
        data-id="hilos-action-error-detail"
      />
    </div>
    <template #actions="{ requestClose }">
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
