<!-- HilosTableEmptyState — the two states the body of a table says in words: the
set is empty and nothing filters it ("data is not here yet"), or it is empty
under a search or a filter ("Nothing found"). Which of the two holds is decided
by the core (tableFrame.ts, HilosTableBody); this view only draws it, and the
table draws it in both of its branches, wide and narrow. The first state speaks
the page's own words — the title and hint of its declared empty state, and its
main action, the same button the bar offers — while the second is the
framework's and names what was searched for, because resetting is only an offer
when the reader can see what will be reset (mockups/components/table section
10). Internal to the Vue view layer on purpose: it is not exported from
index.ts, for the reason the bar is not. -->
<script setup lang="ts" generic="R">
import { computed } from 'vue'
import type { HilosTableFilterView, TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The headless server-windowed controller the state reads and resets. */
  controller: TableViewportController<R>
  /** Which of the two worded states of the body to draw. */
  kind: 'empty' | 'empty_filtered'
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
    <!-- SCAFFOLD: a table with no declared empty state still says what its page
    passed in the empty slot or the emptyText prop. Both go when the five
    framework pages move onto the declaration (HIL-819). -->
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
