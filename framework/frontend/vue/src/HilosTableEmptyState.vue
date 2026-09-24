<!-- HilosTableEmptyState — the four states the body of a table says in words:
the set is empty and nothing filters it ("data is not here yet"), it is empty
under a search or a filter ("Nothing found"), the WINDOW is empty over a set
that is not ("Nothing on this page"), or the server refused this table's window
("List unavailable"). Which of the four holds is decided by the
core (tableFrame.ts, HilosTableBody); this view only draws it, and the table
draws it in both of its branches, wide and narrow. The first state speaks the
page's own words — the title and hint of its declared empty state, and its main
action, the same button the bar offers — while the second is the framework's and
names what was searched for, because resetting is only an offer when the reader
can see what will be reset (mockups/components/table section 10). The third is
the framework's too, and offers neither of those: the set has rows, so creating
one answers nothing and there may be no filter to reset — what the reader needs
is the way back to the rows. The fourth is the framework's as well and offers
nothing: the rows could not be fetched, and the way out is the next window, not
a button. Internal to the Vue view layer on purpose: it is
not exported from index.ts, for the reason the bar is not. -->
<script setup lang="ts" generic="R">
import { computed } from 'vue'
import type { HilosTableFilterView, TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The headless server-windowed controller the state reads and resets. */
  controller: TableViewportController<R>
  /** Which of the four worded states of the body to draw. */
  kind: 'empty' | 'empty_filtered' | 'empty_page' | 'unavailable'
}>()

// What the page declared about the frame, or null when it declared nothing. It
// does not change over the life of a table, so it is read once.
const declaration = props.controller.frame.declaration

const search = useSignal(props.controller.search)
const filters = useSignal(props.controller.frame.filters)

// How one active filter reads in the sentence — the forms the control in the bar
// already reads in (HilosTableFilterControl.vue): a dropdown names its option, a
// date range leaves out a bound that is not there, a toggle is its own label.
function filterTerm(view: HilosTableFilterView): string {
  const filter = view.filter
  if (filter.kind === 'select') {
    const option = filter
      .options()
      .find((declared) => declared.value === view.value)

    return `${filter.label}: ${option === undefined ? String(view.value) : option.label}`
  }
  if (filter.kind === 'date_range') {
    const { from, to } = view.value as { from?: unknown; to?: unknown }
    if (from !== undefined && to !== undefined) {
      return `${filter.label}: ${String(from)} – ${String(to)}`
    }
    if (from !== undefined) {
      return `${filter.label}: from ${String(from)}`
    }
    if (to !== undefined) {
      return `${filter.label}: until ${String(to)}`
    }
  }

  return filter.label
}

// The query and the filters are named together: the reset below takes both off
// with one press, and a sentence promising less than the button does would be
// the wrong thing to read before pressing it.
const terms = computed(() => {
  const parts: string[] = []
  if (search.value !== '') {
    parts.push(`“${search.value}”`)
  }
  for (const view of filters.value) {
    if (view.active) {
      parts.push(filterTerm(view))
    }
  }

  return `No rows match ${parts.join(' · ')}`
})

function pressMainAction(): void {
  declaration?.mainAction?.press()
}
</script>

<template>
  <div
    v-if="kind === 'empty'"
    class="text-center py-4"
    role="status"
    data-id="hilos-table-empty"
  >
    <template v-if="declaration?.empty">
      <i
        class="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
        aria-hidden="true"
      ></i>
      <div
        class="fw-semibold small"
        :class="declaration.empty.hint ? 'mb-1' : 'mb-3'"
        data-id="hilos-table-empty-title"
      >
        {{ declaration.empty.title }}
      </div>
      <p
        v-if="declaration.empty.hint"
        class="small text-body-secondary mb-3"
        data-id="hilos-table-empty-hint"
      >
        {{ declaration.empty.hint }}
      </p>
    </template>
    <!-- A table with no declared empty state still says what its page passed in
    the empty slot or the emptyText prop: the framework's log pages hand their
    words over that way, and no leaf moves them onto the declaration yet. -->
    <div v-else class="text-muted" :class="{ 'mb-3': declaration?.mainAction }">
      <slot />
    </div>
    <button
      v-if="declaration?.mainAction"
      type="button"
      class="btn btn-sm btn-primary"
      data-id="hilos-table-empty-action"
      @click="pressMainAction"
    >
      {{ declaration.mainAction.label }}
    </button>
  </div>

  <div
    v-else-if="kind === 'empty_page'"
    class="text-center py-4"
    role="status"
    data-id="hilos-table-empty-page"
  >
    <i
      class="bi bi-arrow-left-circle fs-1 text-body-secondary mb-2 d-block"
      aria-hidden="true"
    ></i>
    <div class="fw-semibold small mb-1">Nothing on this page</div>
    <p class="small text-body-secondary mb-3">
      These rows moved while the page was open.
    </p>
    <button
      type="button"
      class="btn btn-sm btn-outline-secondary"
      data-id="hilos-table-empty-page-back"
      @click="controller.prevPage()"
    >
      Back to the rows
    </button>
  </div>

  <div
    v-else-if="kind === 'unavailable'"
    class="text-center py-4"
    role="status"
    data-id="hilos-table-unavailable"
  >
    <span class="position-relative d-inline-block mb-2" aria-hidden="true">
      <i class="bi bi-gear fs-1 text-warning"></i>
      <i
        class="bi bi-exclamation-circle-fill text-danger position-absolute hilos-table-unavailable-mark"
      ></i>
    </span>
    <div class="fw-semibold small mb-1" data-id="hilos-table-unavailable-title">
      List unavailable
    </div>
    <p
      class="small text-body-secondary mb-0"
      data-id="hilos-table-unavailable-hint"
    >
      The rows of this list could not be fetched. The rest of the page still
      works.
    </p>
  </div>

  <div
    v-else
    class="text-center py-4"
    role="status"
    data-id="hilos-table-no-matches"
  >
    <i
      class="bi bi-search fs-1 text-body-secondary mb-2 d-block"
      aria-hidden="true"
    ></i>
    <div class="fw-semibold small mb-1">Nothing found</div>
    <p
      class="small text-body-secondary mb-3 text-break"
      data-id="hilos-table-no-matches-terms"
    >
      {{ terms }}
    </p>
    <button
      type="button"
      class="btn btn-sm btn-outline-secondary"
      data-id="hilos-table-no-matches-reset"
      @click="controller.resetFilters()"
    >
      Reset filters
    </button>
  </div>
</template>
