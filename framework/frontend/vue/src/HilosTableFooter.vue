<!-- HilosTableFooter — the strip below a table: which rows of the set are on
screen, how many there are, and the pager. It computes NOTHING about the set —
the core hands over the numbers and the view builds the sentence in its own
language (tableFrame.ts, HilosTableFooter); the one thing it works out is which
page numbers fit in the row, which is a question about the width of a strip and
belongs to nobody else. Page numbers stand there exactly while the count is
exact: a page is a place counted from an end of the set, and a count stopped at
its ceiling has no such end (mockups/components/table section 8). On an empty
window it draws nothing at all: "0 of 0" says nothing, and the body of the table
is what speaks about emptiness. Internal to the Vue view layer on purpose: it is
not exported from index.ts, for the reason the bar is not. -->
<script setup lang="ts" generic="R">
import { computed } from 'vue'
import type { TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

/**
 * One slot of the pager: a page to go to, or the pages passed over between two
 * of them.
 */
type PagerSlot = { kind: 'page'; number: number } | { kind: 'gap' }

/**
 * How many slots the pager holds — five numbers and the two stretches passed
 * over between them. A set that fits in that many pages is drawn whole instead:
 * skipping over pages there would save no room and only hide where the reader
 * can go.
 */
const PAGER_SLOTS = 7

const props = defineProps<{
  /** The headless server-windowed controller the footer reads and pages. */
  controller: TableViewportController<R>
}>()

const footer = useSignal(props.controller.frame.footer)

// The total reads as "at least this many" when the count stopped at its ceiling,
// which is what the trailing plus says.
const countLabel = computed(() => {
  const { firstRow, lastRow, totalCount, totalExact } = footer.value

  return `${firstRow} – ${lastRow} of ${totalCount}${totalExact ? '' : '+'}`
})

// The pager's slots: the first page, the last one, and the current page with its
// two neighbours — the places a reader can reach in one step. Two held numbers
// with a single page between them keep that page rather than an ellipsis
// standing in for it, which would take the same room and offer less.
const pages = computed<readonly PagerSlot[]>(() => {
  const { page, pageCount } = footer.value
  if (pageCount === null) {
    return []
  }
  if (pageCount <= PAGER_SLOTS) {
    return Array.from({ length: pageCount }, (_unused, index) => ({
      kind: 'page',
      number: index + 1,
    }))
  }

  const current = page + 1
  const held = [...new Set([1, current - 1, current, current + 1, pageCount])]
    .filter((number) => number >= 1 && number <= pageCount)
    .sort((one, other) => one - other)

  return held.flatMap<PagerSlot>((number, index) => {
    const previous = held[index - 1]
    if (previous === undefined || number - previous === 1) {
      return [{ kind: 'page', number }]
    }

    return [
      number - previous === 2
        ? { kind: 'page', number: number - 1 }
        : { kind: 'gap' },
      { kind: 'page', number },
    ]
  })
})
</script>

<template>
  <div
    v-if="footer.firstRow > 0"
    class="d-flex flex-wrap align-items-center gap-2 mt-3"
  >
    <span class="small text-body-secondary" data-id="hilos-table-count">
      {{ countLabel }}
    </span>
    <div
      class="ms-auto btn-group btn-group-sm"
      role="group"
      aria-label="Pagination"
    >
      <button
        type="button"
        class="btn btn-outline-secondary"
        :disabled="!footer.hasPreviousPage"
        :aria-label="pages.length > 0 ? 'Previous page' : undefined"
        data-id="hilos-table-prev"
        @click="controller.prevPage()"
      >
        <i
          class="bi bi-chevron-left"
          :class="{ 'me-1': pages.length === 0 }"
          aria-hidden="true"
        ></i
        ><template v-if="pages.length === 0">Previous</template>
      </button>
      <template
        v-for="(slot, index) in pages"
        :key="slot.kind === 'page' ? `page-${slot.number}` : `gap-${index}`"
      >
        <button
          v-if="slot.kind === 'page' && slot.number !== footer.page + 1"
          type="button"
          class="btn btn-outline-secondary"
          :aria-label="`Page ${slot.number}`"
          :data-id="`hilos-table-page-${slot.number}`"
          @click="controller.setPage(slot.number - 1)"
        >
          {{ slot.number }}
        </button>
        <button
          v-else-if="slot.kind === 'page'"
          type="button"
          class="btn btn-outline-secondary active"
          aria-current="page"
          disabled
          :data-id="`hilos-table-page-${slot.number}`"
        >
          {{ slot.number }}
        </button>
        <span
          v-else
          class="btn btn-outline-secondary disabled"
          aria-hidden="true"
          >…</span
        >
      </template>
      <button
        type="button"
        class="btn btn-outline-secondary"
        :disabled="!footer.hasNextPage"
        :aria-label="pages.length > 0 ? 'Next page' : undefined"
        data-id="hilos-table-next"
        @click="controller.nextPage()"
      >
        <template v-if="pages.length === 0">Next</template
        ><i
          class="bi bi-chevron-right"
          :class="{ 'ms-1': pages.length === 0 }"
          aria-hidden="true"
        ></i>
      </button>
    </div>
  </div>
</template>
