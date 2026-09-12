<!-- HilosViewportTable — the thin Vue view over the SERVER-WINDOWED
TableViewportController. Search, sort, and paging change the viewport descriptor
and are sent to the backend (NO local filtering); live changes arrive as pending
and are resolved with the Apply button. A removed row renders as a placeholder in
its slot — the layout never collapses. It holds NO table logic
(multiframework-core.md): the controller owns the descriptor, pending, and Apply.
Body cells come from the `#row` slot, plus one framework-owned cell at the end
of the row carrying the waiting mark while anything waits; the placeholder,
header, paging, and the two strips of live change stay framework-owned.
Running work is drawn as a bar — above the table for work over the set, and in a
row of its own under a row for work over that one row, stretched under whichever
columns declared themselves. The room and the track are the framework's, while
everything a reader sees beside them comes from the page through slots
(mockups/components/table section 5).
(Distinct from HilosTable, the client-side view.)
It draws its frame from what the page DECLARED (HilosTableBar, HilosTableFooter)
when the controller carries a declaration, and from its own props when it does
not — two epochs of the same table living side by side while the five framework
pages have not moved onto the declaration yet (HIL-819). -->

<script setup lang="ts" generic="R">
import { computed, useId } from 'vue'
import {
  hilosTableOrderPosition,
  hilosTableSortPositionLabel,
} from '@hilos/core'
import type {
  HilosTableColumn,
  // Aliased because the component drawing one of these carries the same name.
  HilosTableProgress as TableProgressBar,
  TableSort,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import HilosTableBar from './HilosTableBar.vue'
import HilosTableFooter from './HilosTableFooter.vue'
import HilosTableProgress from './HilosTableProgress.vue'
import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The headless server-windowed controller driving rows, descriptor, and pending. */
    controller: TableViewportController<R>
    /** Column declarations for the header (labels and sort controls). */
    columns: HilosTableColumn[]
    /** Accessible name for the table, rendered as a visually-hidden caption. */
    label?: string
    /** Show the search box above the table. */
    searchable?: boolean
    /** Placeholder for the search box. */
    searchPlaceholder?: string
    /** Message shown when there are no rows. */
    emptyText?: string
    /** Message shown while the first window is still loading. */
    loadingText?: string
    /** Label shown in a removed row's placeholder slot. */
    placeholderText?: string
    /**
     * The `data-id` the table's root carries, for a page that draws more than one of
     * them: the default is the shared handle, and a second table on the same page names
     * itself so the two can be told apart from outside.
     */
    dataId?: string
  }>(),
  {
    label: undefined,
    searchable: false,
    searchPlaceholder: 'Search…',
    emptyText: 'No rows.',
    loadingText: 'Loading…',
    placeholderText: 'Removed',
    dataId: 'hilos-viewport-table',
  },
)

// What the page declared about the frame, or null when it declared nothing —
// the one switch between the two epochs of markup. It does not change over the
// life of a table, so it is read once rather than wrapped in a signal.
const declaration = props.controller.frame.declaration

// The declared title names the table through aria-labelledby, so the id is
// minted here — where both the bar that renders the heading and the table that
// points at it can see it (Flow F9).
const titleId = useId()

const rows = useSignal(props.controller.rows)
const search = useSignal(props.controller.search)
const order = useSignal(props.controller.order)
const page = useSignal(props.controller.page)
const pageCount = useSignal(props.controller.pageCount)
const totalCount = useSignal(props.controller.totalCount)
const totalExact = useSignal(props.controller.totalExact)
const hasNextPage = useSignal(props.controller.hasNextPage)
const pendingCount = useSignal(props.controller.pendingCount)
const announced = useSignal(props.controller.announced)
const loaded = useSignal(props.controller.loaded)

// The bars this view draws. The third place of work, the bulk bar, lives inside
// the selection panel, and there is no panel in Vue yet (HIL-804) — drawn
// anywhere else it would take the room the table bar gives to the project.
const tableProgress = useSignal(props.controller.progress.table)
const rowProgress = useSignal(props.controller.progress.rows)

// A table whose count stopped at its ceiling has no page count to compare against, and the
// footer is exactly what such a table still needs: it is the only place saying there is more.
const paginated = computed(
  () => pageCount.value === null || pageCount.value > 1,
)

// The mark column stands exactly while the waiting strip does. Header cell and body
// cell read the SAME condition, so the two cannot drift apart into a row wider than
// its header.
const markColumn = computed(() => pendingCount.value > 0)

// Every cell that spans the whole row — the placeholder of a removed row, the empty
// and loading states — counts the mark column while it stands.
const bodyColspan = computed(
  () => props.columns.length + (markColumn.value ? 1 : 0),
)

/** One cell of a row bar's row: how many columns it spans, and whether the bar is under it. */
type ProgressCell = { span: number; covered: boolean }

// The cells of a row bar's row, in the order the columns are declared: runs of
// marked columns merge into one covered cell, runs of unmarked ones into one
// empty cell, and the waiting cell is added exactly while it stands over the
// ordinary rows. No column marked means one covered cell across the whole row.
//
// The spans add up to bodyColspan by construction, which is what keeps the row
// from growing wider than its header the moment a waiting change appears.
const progressCells = computed<readonly ProgressCell[]>(() => {
  const cells: ProgressCell[] = []
  for (const column of props.columns) {
    const covered = column.progress === true
    const last = cells[cells.length - 1]
    if (last !== undefined && last.covered === covered) {
      last.span += 1
    } else {
      cells.push({ span: 1, covered })
    }
  }
  if (!props.columns.some((column) => column.progress === true)) {
    cells.splice(0, cells.length, { span: props.columns.length, covered: true })
  }
  if (markColumn.value) {
    cells.push({ span: 1, covered: false })
  }

  return cells
})

// The total reads as "at least this many" when the count stopped at its ceiling, which is
// what the trailing plus says. Spelling it out in words would say the same thing longer.
const countLabel = computed(() =>
  totalExact.value ? `${totalCount.value} total` : `${totalCount.value}+ total`,
)

// The numeral of each strip is chosen here rather than in the template: '1 rows'
// would stand in the most visible place of the screen.
const announceLabel = computed(() =>
  announced.value.above === 1
    ? '1 new row above the window'
    : `${announced.value.above} new rows above the window`,
)

// Only the tail of the waiting sentence is composed here, because the count itself
// stays a node of its own under `hilos-table-pending` — the handle the outside reads
// the number by.
const pendingSuffix = computed(() =>
  pendingCount.value === 1
    ? 'row will move or leave'
    : 'rows will move or leave',
)

// A row's tint, resolved in the order the mockup resolves it (section 4): amber
// while a pending change waits on the row — a move and a removal alike — and green
// for the couple of seconds after a value landed. Waiting outranks the highlight,
// because it is the one of the two the reader still has to act on. Red belongs to a
// refused write, not to waiting. Bootstrap's contextual row classes carry their own
// dark-mode variants, so they adapt to the active theme with no custom style layer.
function rowClass(view: TableViewportRow<R>): string | undefined {
  if (view.pending !== null) {
    return 'table-warning'
  }

  return view.highlighted ? 'table-success' : undefined
}

// The bar running over one row, or undefined when none is. Read out of the map
// by key rather than off the row's own projection, which is the tie the channel
// exists to cut (tableProgress.ts): the bar outlives the window the row sits in.
function rowBar(rowKey: string): TableProgressBar | undefined {
  return rowProgress.value.get(rowKey)
}

// The arrow a header carries: every column the order runs by gets one, because
// an order of two columns is sorted by both of them and a single arrow would
// name one of the two as the whole answer.
function sortComponent(key: string): TableSort | undefined {
  return order.value?.find((component) => component.field === key)
}

function sortIcon(key: string): string {
  const component = sortComponent(key)
  if (component === undefined) {
    return 'bi-arrow-down-up text-muted'
  }

  return component.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
}

// The place a column takes in a composite order — null under an order of one
// column, where the arrow already says everything. The arithmetic is the core's:
// a view that counted the places itself would be a second answer to the question
// the menu answers (tableSortOrder.ts).
function sortPosition(key: string): number | null {
  return hilosTableOrderPosition(order.value, key)
}

// A sortable header reports its current sort state to assistive tech through
// aria-sort: a sortable-but-unsorted column reports 'none', the active column
// its direction, and a non-sortable column nothing at all.
function ariaSort(
  column: HilosTableColumn,
): 'ascending' | 'descending' | 'none' | undefined {
  if (!column.sortable) {
    return undefined
  }
  const component = sortComponent(column.key)
  if (component === undefined) {
    return 'none'
  }

  return component.direction === 'asc' ? 'ascending' : 'descending'
}

function onSearchInput(event: Event): void {
  props.controller.setSearch((event.target as HTMLInputElement).value)
}
</script>

<template>
  <div :data-id="dataId">
    <HilosTableBar
      v-if="declaration"
      :controller="controller"
      :title-id="titleId"
    />

    <!-- SCAFFOLD: the bar a table draws from props, kept while the five
    framework pages still pass them. It goes with the props themselves when
    those pages move onto the declaration (HIL-819). -->
    <div v-if="!declaration && searchable" class="mb-3">
      <input
        type="search"
        class="form-control"
        :placeholder="searchPlaceholder"
        :aria-label="searchPlaceholder"
        :value="search"
        data-id="hilos-table-search"
        @input="onSearchInput"
      />
    </div>

    <!-- Work running over the set as a whole: the framework gives the room and
    draws the track, and everything a reader sees beside it — a title, a counter,
    a link, a Stop button — comes from the page through the two slots, because it
    is the project's business logic and not the framework's (mockup section 5,
    plate 2). It stands ABOVE both strips of live change: it speaks about work
    over the set, while they speak about what has already happened to it. -->
    <div
      v-if="tableProgress"
      class="d-flex align-items-start gap-2 mb-2 p-2 rounded border"
      data-id="hilos-table-progress"
    >
      <div class="flex-grow-1">
        <!-- The caption line stands only where the page filled it: a line that
        holds its margin with nothing in it is not a reserve but a gap
        (Flow F7). -->
        <div v-if="$slots['table-progress']" class="small mb-1">
          <slot name="table-progress" :progress="tableProgress" />
        </div>
        <HilosTableProgress
          :progress="tableProgress"
          label="Work on this table"
        />
      </div>
      <slot name="table-progress-action" :progress="tableProgress" />
    </div>

    <!-- The two strips of live change, in the order of the mockup and outside
    both epochs of the frame: they speak about what is happening to the rows,
    not about what the page declared. -->
    <div
      v-if="announced.above > 0"
      class="alert alert-secondary py-2 px-3 d-flex flex-wrap align-items-center gap-2 mb-2"
      role="status"
      data-id="hilos-table-announce"
    >
      <i class="bi bi-arrow-down-circle" aria-hidden="true"></i>
      <span class="small">{{ announceLabel }}</span>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary ms-auto"
        data-id="hilos-table-announce-show"
        @click="controller.show()"
      >
        Show
      </button>
    </div>

    <div
      v-if="pendingCount > 0"
      class="alert alert-warning py-2 px-3 d-flex flex-wrap align-items-center gap-2 mb-2"
      role="status"
    >
      <i class="bi bi-pause-circle" aria-hidden="true"></i>
      <span class="small">
        <span data-id="hilos-table-pending">{{ pendingCount }}</span>
        {{ pendingSuffix }}
      </span>
      <button
        type="button"
        class="btn btn-sm btn-warning ms-auto"
        data-id="hilos-table-apply"
        @click="controller.apply()"
      >
        Apply
      </button>
    </div>

    <div class="table-responsive">
      <table
        class="table table-striped table-hover align-middle mb-0"
        :aria-labelledby="declaration ? titleId : undefined"
      >
        <!-- A declared table already shows its name as a heading, and a hidden
        caption repeating it would name the table twice (Flow F9). -->
        <caption v-if="!declaration && label" class="visually-hidden">
          {{
            label
          }}
        </caption>
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :class="column.headerClass"
              :aria-sort="ariaSort(column)"
            >
              <button
                v-if="column.sortable"
                type="button"
                class="btn btn-link p-0 text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                :data-id="`hilos-table-sort-${column.key}`"
                @click="controller.setSort(column.key)"
              >
                {{ column.label }}
                <i :class="['bi', sortIcon(column.key)]" aria-hidden="true"></i>
                <template v-if="sortPosition(column.key) !== null">
                  <sup aria-hidden="true">{{ sortPosition(column.key) }}</sup>
                  <span class="visually-hidden">
                    {{
                      hilosTableSortPositionLabel(
                        sortPosition(column.key)!,
                        order!.length,
                      )
                    }}
                  </span>
                </template>
              </button>
              <template v-else>{{ column.label }}</template>
            </th>
            <th v-if="markColumn" scope="col" class="text-end">
              <span class="visually-hidden">Waiting change</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <template v-for="view in rows" :key="view.rowKey">
            <tr
              :data-id="`hilos-table-row-${view.rowKey}`"
              :class="rowClass(view)"
            >
              <td
                v-if="view.placeholder || view.row === null"
                :colspan="bodyColspan"
                class="text-center text-muted fst-italic"
                data-id="hilos-table-placeholder"
              >
                {{ placeholderText }}
              </td>
              <slot v-else name="row" :row="view.row" :row-key="view.rowKey" />
              <td
                v-if="markColumn && !view.placeholder && view.row !== null"
                class="text-end text-nowrap"
              >
                <span
                  v-if="view.pending === 'move'"
                  class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                  :data-id="`hilos-table-pending-move-${view.rowKey}`"
                >
                  <i class="bi bi-arrows-move" aria-hidden="true"></i> Will move
                </span>
                <span
                  v-else-if="view.pending === 'remove'"
                  class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                  :data-id="`hilos-table-pending-remove-${view.rowKey}`"
                >
                  <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Will
                  leave
                </span>
              </td>
            </tr>

            <!-- Work running over this one row, drawn right under it and only
            while the row is on screen: a key absent from the window takes up
            nothing and comes back with its row. A row drawn as a placeholder
            gets no bar under it even if the key is still in the map — the core
            takes a removed row's bar down at once, so that is a race rather
            than a normal state, and the condition here is the same pair that
            draws the placeholder above. -->
            <tr
              v-if="
                !view.placeholder &&
                view.row !== null &&
                rowBar(view.rowKey) !== undefined
              "
              :data-id="`hilos-table-progress-row-${view.rowKey}`"
            >
              <td
                v-for="(cell, index) in progressCells"
                :key="index"
                :colspan="cell.span"
                class="pt-0"
              >
                <template v-if="cell.covered">
                  <div
                    v-if="$slots['row-progress']"
                    class="small text-body-secondary mb-1"
                  >
                    <slot
                      name="row-progress"
                      :progress="rowBar(view.rowKey)!"
                      :row-key="view.rowKey"
                    />
                  </div>
                  <HilosTableProgress
                    :progress="rowBar(view.rowKey)!"
                    label="Work on this row"
                  />
                </template>
              </td>
            </tr>
          </template>
          <tr v-if="rows.length === 0">
            <td :colspan="bodyColspan" class="text-center text-muted py-4">
              <span
                v-if="!loaded"
                class="d-inline-flex align-items-center gap-2"
                role="status"
                data-id="hilos-table-loading"
              >
                <span
                  class="spinner-border spinner-border-sm"
                  aria-hidden="true"
                ></span>
                {{ loadingText }}
              </span>
              <slot v-else name="empty">{{ emptyText }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <HilosTableFooter v-if="declaration" :controller="controller" />

    <!-- SCAFFOLD: the footer a table draws from its own comparisons, kept for
    the same reason and going the same way as the bar above (HIL-819). -->
    <div
      v-if="!declaration && paginated"
      class="d-flex justify-content-between align-items-center mt-3"
    >
      <span class="text-muted small" data-id="hilos-table-count">
        {{ countLabel }}
      </span>
      <div class="btn-group" role="group" aria-label="Pagination">
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="page === 0"
          data-id="hilos-table-prev"
          @click="controller.prevPage()"
        >
          Previous
        </button>
        <span
          v-if="pageCount !== null"
          class="btn btn-sm disabled"
          :aria-label="`Page ${page + 1} of ${pageCount}`"
          data-id="hilos-table-page"
        >
          {{ page + 1 }} / {{ pageCount }}
        </span>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="!hasNextPage"
          data-id="hilos-table-next"
          @click="controller.nextPage()"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>
