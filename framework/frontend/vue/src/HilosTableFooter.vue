<!-- HilosTableFooter — the strip below a table: which rows of the set are on
screen, how many there are, and the two steps of the pager. It computes NOTHING
of its own — the core hands over the numbers and the view builds the sentence in
its own language (tableFrame.ts, HilosTableFooter). On an empty window it draws
nothing at all: "0 of 0" says nothing, and the body of the table is what speaks
about emptiness. Internal to the Vue view layer on purpose: it is not exported
from index.ts, for the reason the bar is not. -->
<script setup lang="ts" generic="R">
import { computed } from 'vue'
import type { TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

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
        data-id="hilos-table-prev"
        @click="controller.prevPage()"
      >
        <i class="bi bi-chevron-left me-1" aria-hidden="true"></i>Previous
      </button>
      <button
        type="button"
        class="btn btn-outline-secondary"
        :disabled="!footer.hasNextPage"
        data-id="hilos-table-next"
        @click="controller.nextPage()"
      >
        Next<i class="bi bi-chevron-right ms-1" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</template>
