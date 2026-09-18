<!-- HilosFormError — the refusal of a form, drawn in room that was taken before
the refusal existed. The block is exactly one line tall and never changes
height: with no refusal an invisible twin of the very same markup holds the
room, so showing the refusal moves nothing the person is reading. The room is
held by that twin and not by a height of our own, because the twin is as tall as
the row turns out to be at this width and this font, and because a declaration
of our own is not ours to write (styling-rules.md); the trick is LoadingButton's.
One line at any length: the text is truncated with an ellipsis and the whole of
it lives behind the details button, which stands at every refusal and is always
the same width — a button that came and went would change the row from refusal to
refusal, and a truncated text would have nowhere to open. Truncation is visual
only, so the node's text stays whole.
This row is the one refusal row of the SDK: HilosActionError draws a tracked
action's refusal by mounting it, and what that needs beyond a form's sentence —
the class name beside the details icon, the original text under an "Exception"
caption, the Copy button, a live region on the slot — lives here as inputs that
are off by default, one behavior rather than a second copy of the row.
The row carries no role at all. A form's voice is the surface's own permanent
live region, kept apart from the sight of it (accessibility.md) — a live region
living inside a form that swaps its steps would die with its step; only a
surface that stays put under the row makes the slot itself the region
(`announce`). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import HilosLongText from './HilosLongText.vue'
import HilosModal from './HilosModal.vue'

const props = withDefaults(
  defineProps<{
    /** The refusal to draw; an empty string means the same as null — no refusal. */
    message: string | null
    /** The data-id of the visible row; the slot and the idle twin derive theirs from it. */
    dataId: string
    /** Short class name of what failed; drawn beside the details icon and above the original text. */
    errorType?: string | null
    /** The failure's original text; when not empty, the details panel shows it under "Exception". */
    errorDetail?: string | null
    /** What the details panel's Copy button copies; empty means no Copy button. */
    copyText?: string
    /**
     * Make the slot itself the live region (role=alert, aria-live=assertive).
     * Only for a surface that does not change under the row, such as an admin
     * modal; a form that swaps its steps leaves it off and keeps its voice on
     * the surface, because a region inside a step would die with the step
     * (accessibility.md, "The room belongs to the block, the voice to the
     * surface").
     */
    announce?: boolean
  }>(),
  { errorType: null, errorDetail: null, copyText: '', announce: false },
)

/**
 * The row and its idle twin, to the character — only `invisible` differs. The
 * room equals the true height of the row exactly while the markup matches.
 */
const ROW_CLASS =
  'alert alert-danger small py-1 px-2 my-2 d-flex align-items-center gap-2'

/** The details button and the inert copy of it the twin holds the room for. */
const DETAILS_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

const detailOpen = ref(false)
// An empty string is the absence of a refusal, the same as null: one meaning,
// one behavior — otherwise an empty string would draw a red row about nothing.
const shown = computed(() =>
  (props.message ?? '') === '' ? null : props.message,
)
// An original text that was not sent is not a shorter block but no block.
const hasDetail = computed(() => (props.errorDetail ?? '') !== '')
// The class name heads the original text, so what is read — and copied — names
// what failed.
const detailText = computed(() =>
  props.errorType
    ? `${props.errorType}\n${props.errorDetail ?? ''}`
    : (props.errorDetail ?? ''),
)

// A cleared refusal takes the panel with it: the form re-arms on the next
// attempt, and a panel left open would be showing the previous one's text.
watch(shown, (value) => {
  if (value === null) {
    detailOpen.value = false
  }
})
</script>

<template>
  <div
    :data-id="`${dataId}-slot`"
    :role="announce ? 'alert' : undefined"
    :aria-live="announce ? 'assertive' : undefined"
  >
    <div v-if="shown !== null" :class="ROW_CLASS" :data-id="dataId">
      <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">{{ shown }}</span>
      <button
        type="button"
        :class="DETAILS_CLASS"
        aria-label="Show error details"
        title="Show error details"
        :data-id="`${dataId}-details`"
        @click="detailOpen = true"
      >
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span
          v-if="errorType"
          class="d-none d-sm-inline ms-1"
          :data-id="`${dataId}-type`"
          >{{ errorType }}</span
        >
      </button>
    </div>
    <div
      v-else
      :class="[ROW_CLASS, 'invisible']"
      aria-hidden="true"
      :data-id="`${dataId}-idle`"
    >
      <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">&nbsp;</span>
      <!-- A span, not a button: the twin holds room, it does not take focus. -->
      <span :class="DETAILS_CLASS">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
      </span>
    </div>
  </div>

  <HilosModal
    v-model="detailOpen"
    title="Error details"
    :copy-text="copyText"
    initial-focus="dialog"
  >
    <div class="d-flex flex-column gap-3">
      <HilosLongText
        kind="prose"
        :text="shown ?? ''"
        :data-id="`${dataId}-full`"
      />
      <div v-if="hasDetail">
        <div class="small text-body-secondary mb-1">Exception</div>
        <HilosLongText
          kind="output"
          :text="detailText"
          :data-id="`${dataId}-detail`"
        />
      </div>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :data-id="`${dataId}-close`"
        @click="requestClose"
      >
        Close
      </button>
    </template>
  </HilosModal>
</template>
