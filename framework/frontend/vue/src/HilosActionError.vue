<!-- HilosActionError — the refusal of a tracked action, drawn where the person
acted. The "server refused" row of the modal mockup: at the top of the modal
body and above the fields, where a toast alone would fly away from the form it
belongs to (toasts.md).
It draws nothing of its own. The row, its invisible twin, the truncation, the
details button and the details modal are HilosFormError's; this component
translates a TrackedAction into that row's inputs — the sentence, the class name
of what actually failed (the sign the framework held something back, HIL-779),
the original text, and what Copy copies — so a change to how a refusal looks is
made in one place, not two (rules-and-violations.md).
`detailsTitle` is the one thing the plate cannot learn from the action: what it
failed to do, which only the place that mounts it knows.
The slot is the live region here, and not on a form: an admin surface does not
change under the row, so a region on the slot lives as long as the room does and
announces each refusal once (accessibility.md). A form that swaps its steps
keeps its voice on the surface instead, which is why `announce` is set here and
nowhere else.
`suppressed` says "this same action is answering somewhere else right now": the
room stays, the voice goes, and an open details panel closes with it. It has to
be the room that stays — a page that drops the row to keep it out of its own
confirmation moves everything under the backdrop and hands it back shifted.
Bootstrap classes only. -->
<script setup lang="ts">
import { computed } from 'vue'

import HilosFormError from './HilosFormError.vue'
import type { TrackedAction } from './useTrackedAction.js'

const props = withDefaults(
  defineProps<{
    /** The tracked action whose latest failure this draws; the room is held either way. */
    action: TrackedAction
    /** Hold the room and stay silent — this action is answering somewhere else. */
    suppressed?: boolean
    /**
     * What the action failed to do, as the heading of its details panel - a
     * verb, the way every modal title is: "Couldn't save", "Couldn't delete the
     * backup". Required: a panel headed "Error details" tells the person nothing
     * the row did not.
     */
    detailsTitle: string
  }>(),
  { suppressed: false },
)

// The message is read through a computed and never as `action.error` in the
// template: it is a ref sitting on a plain object, and Vue unwraps no such ref
// in a template expression — `v-if="action.error"` is true even with no message
// at all, which is how an empty red plate reached a live screen
// (docs/agents/code-style/vue-template-refs.md).
const message = computed(() => props.action.error.value)
const errorType = computed(() => props.action.failure.value?.errorType ?? null)
const errorDetail = computed(
  () => props.action.failure.value?.errorDetail ?? null,
)
// Copy carries what the original-text block shows when there is one — the class
// name heading the original text — and the sentence when there is not: it is
// copied to be pasted into a ticket, and what is pasted is the long half.
const copyText = computed(() => {
  if ((errorDetail.value ?? '') === '') {
    return message.value ?? ''
  }
  return errorType.value
    ? `${errorType.value}\n${errorDetail.value}`
    : (errorDetail.value ?? '')
})
</script>

<template>
  <HilosFormError
    :message="suppressed ? null : message"
    data-id="hilos-action-error"
    :error-type="errorType"
    :error-detail="errorDetail"
    :copy-text="copyText"
    :details-title="detailsTitle"
    announce
  />
</template>
