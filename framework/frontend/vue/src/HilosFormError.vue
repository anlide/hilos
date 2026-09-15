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
only, so the node's text stays whole. The row carries no role at all: what a
screen reader hears is the surface's own permanent live region, kept apart from
the sight of it (accessibility.md) — a live region living inside a form that
swaps its steps would die with its step. -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import HilosModal from './HilosModal.vue'

const props = defineProps<{
  /** The refusal to draw; an empty string means the same as null — no refusal. */
  message: string | null
  /** The data-id of the visible row; the slot and the idle twin derive theirs from it. */
  dataId: string
}>()

/**
 * The row and its idle twin, to the character — only `invisible` differs. The
 * room equals the true height of the row exactly while the markup matches.
 */
const ROW_CLASS =
  'alert alert-danger small py-1 px-2 my-2 d-flex align-items-center gap-2'

const detailOpen = ref(false)
// An empty string is the absence of a refusal, the same as null: one meaning,
// one behavior — otherwise an empty string would draw a red row about nothing.
const shown = computed(() =>
  (props.message ?? '') === '' ? null : props.message,
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
  <div :data-id="`${dataId}-slot`">
    <div v-if="shown !== null" :class="ROW_CLASS" :data-id="dataId">
      <i class="bi bi-exclamation-circle flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">{{ shown }}</span>
      <button
        type="button"
        class="btn btn-link btn-sm p-0 lh-1 flex-shrink-0"
        aria-label="Show the full message"
        title="Show the full message"
        :data-id="`${dataId}-details`"
        @click="detailOpen = true"
      >
        <i class="bi bi-info-circle" aria-hidden="true"></i>
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
      <span class="btn btn-link btn-sm p-0 lh-1 flex-shrink-0">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
      </span>
    </div>
  </div>

  <HilosModal v-model="detailOpen" title="Error details" initial-focus="dialog">
    <p class="mb-0" :data-id="`${dataId}-full`">{{ shown }}</p>
  </HilosModal>
</template>
