<!-- HilosViewportTable — the thin Vue view over the SERVER-WINDOWED
TableViewportController. Search, sort, and paging change the viewport descriptor
and are sent to the backend (NO local filtering); live changes arrive as pending
and are resolved with the Apply button. A removed row renders as a placeholder in
its slot — the layout never collapses. It holds NO table logic
(multiframework-core.md): the controller owns the descriptor, pending, and Apply.
Body cells come from a `#cell-<key>` slot per declared column — the page gives
the content, the framework writes the cell — or from the one `#row` slot while a
page still passes its columns as a prop; plus one framework-owned cell at the end
of the row carrying the state of that row — it waits, its values are behind, or
both — and the control that opens the row; the placeholder, header, paging, and
the one room of live messages above the rows stay framework-owned.
A field that did not fit a column of its own is declared `detail` and waits in a
panel under the row: the framework owns the room, the order and the labels, while
the page draws every value through a `#detail-<key>` slot, exactly as it draws a
cell (mockups/components/table section 4).
A table whose page declared bulk operations also carries the framework's checkbox
column, on whichever edge the installation provided — one choice for the whole
application rather than a prop of this table (mockups/components/table section 6).
Running work is drawn as a bar — above the table for work over the set, and in a
row of its own under a row for work over that one row, stretched under whichever
columns declared themselves. The room and the track are the framework's, while
everything a reader sees beside them comes from the page through slots
(mockups/components/table section 5).
(Distinct from HilosTable, the client-side view.)
On a narrow screen a declared table is drawn as a list of cards instead: the
framework builds each card out of the very columns the page declared and fills
it from the same `#cell-<key>` slots, so a project writes no second markup for
its phone (mockups/components/table section 9). A card opens into its own panel,
inside its own body and off an id base of its own. Both branches stand in the
document and Bootstrap's visibility utilities show one of them.
It draws its frame from what the page DECLARED (HilosTableBar, HilosTableFooter)
when the controller carries a declaration, and from its own props when it does
not — two epochs of the same table living side by side: the framework's admin
tables declare, while its log pages still pass props.
The body is drawn from the state the core decides (HilosTableBody): rows, a
skeleton of rows while a window change is late, or one of the three worded states
drawn by HilosTableEmptyState — the page's own "nothing here yet" and the
framework's "Nothing found" (mockups/components/table section 10). -->

<script setup lang="ts" generic="R">
import { computed, inject, useId, useSlots } from 'vue'
import {
  TABLE_DETAIL_COPY,
  TABLE_STALENESS_COPY,
  hilosTableDetailFields,
  hilosTableOrderPosition,
  hilosTableSortPositionLabel,
  hilosTableStaleColumns,
  hilosTableStaleSources,
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
import HilosTableEmptyState from './HilosTableEmptyState.vue'
import HilosTableFooter from './HilosTableFooter.vue'
import HilosTableLive from './HilosTableLive.vue'
import HilosTableProgress from './HilosTableProgress.vue'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'
import { hilosTableSelectionEdgeKey } from './hilosTableSelectionEdge.js'
import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The headless server-windowed controller driving rows, descriptor, and pending. */
    controller: TableViewportController<R>
    /**
     * Column declarations for the header (labels and sort controls) of a table whose
     * page declared no frame; a declared table takes its columns from the declaration
     * and is not handed these.
     */
    columns?: HilosTableColumn[]
    /** Accessible name for the table, rendered as a visually-hidden caption. */
    label?: string
    /** Show the search box above the table. */
    searchable?: boolean
    /** Placeholder for the search box. */
    searchPlaceholder?: string
    /** Message shown when there are no rows. */
    emptyText?: string
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
    columns: () => [],
    label: undefined,
    searchable: false,
    searchPlaceholder: 'Search…',
    emptyText: 'No rows.',
    placeholderText: 'Removed',
    dataId: 'hilos-viewport-table',
  },
)

// What the page declared about the frame, or null when it declared nothing —
// the one switch between the two epochs of markup. It does not change over the
// life of a table, so it is read once rather than wrapped in a signal.
const declaration = props.controller.frame.declaration

// The columns the table is drawn from: the declaration's own where there is one,
// and the prop for a table whose page still passes one (the framework's log pages).
// Everything that measures or draws a column — the header, the cells of a row,
// the width of a full-row cell, the card — counts THIS list, so a declared table
// cannot assemble its row from one list and its card from another (Design D7).
// It is the one reader of the prop left in the component.
const frameColumns = computed<readonly HilosTableColumn[]>(
  () => declaration?.columns ?? props.columns,
)

// Which declared column takes which place of the card a row is drawn as on a
// narrow screen — the head, the badge beside it, the labelled lines, the
// controls. The core derived it from the declaration (tableCard.ts) and the view
// has no arithmetic of its own about it; like the declaration it follows from, it
// is a constant over the life of a table and null exactly when that is.
const card = props.controller.frame.card

// The slots as the page filled them — read to tell a place of the card the page
// draws nothing into from one it does (Flow F3).
const slots = useSlots()

// The declared title names the table through aria-labelledby, so the id is
// minted here — where both the bar that renders the heading and the table that
// points at it can see it (Flow F9).
const titleId = useId()

// What names a declared table: its own title when it declared one, and otherwise the
// heading of the page it stands on, which already names it (Design D3). Undefined for a
// table that declared neither and stands outside an admin page — it has no name to take.
const pageHeadingId = inject(hilosPageHeadingIdKey, undefined)
const nameId = declaration?.title ? titleId : pageHeadingId

// The base every expanded row's panel takes its id from — minted the same way the
// title above is, and for the same reason: the control that points at a panel and
// the panel itself are drawn in two places of one template.
const detailBaseId = useId()

// The base the panel inside a CARD takes its id from — a second one, minted for
// the same table. An id is unique in a document and both branches stand in it at
// once, so a card borrowing the row's id would put that id in twice and break the
// tie between control and panel on both.
const cardDetailBaseId = useId()

// Which edge the checkbox column sits on — one choice for the whole installation
// and not a prop of this table, because two tables of one product disagreeing
// about it is the very thing the rule forbids (Design D5). A project that
// provided nothing gets the left edge, where lists usually keep it.
const selectionEdge = inject(hilosTableSelectionEdgeKey, 'start')

// The marks, and the one sign that this table has them: a page that declared bulk
// operations. There is no second sign — a table drawing its frame from props has
// no declaration, so `enabled` is already false for it (Flow F14).
const selection = props.controller.selection
const selectionEnabled = selection.enabled
const selectionHeader = useSignal(selection.header)

const rows = useSignal(props.controller.rows)
const search = useSignal(props.controller.search)
const order = useSignal(props.controller.order)
const page = useSignal(props.controller.page)
const pageCount = useSignal(props.controller.pageCount)
const totalCount = useSignal(props.controller.totalCount)
const totalExact = useSignal(props.controller.totalExact)
const hasNextPage = useSignal(props.controller.hasNextPage)
const hasPreviousPage = useSignal(props.controller.hasPreviousPage)
const pendingCount = useSignal(props.controller.pendingCount)

// Which state the body is in — rows, the skeleton, or one of the three worded
// states. The core decides it (tableFrame.ts, HilosTableBody) so that the three
// view layers cannot decide it three ways, and both branches below read this one
// answer.
const body = useSignal(props.controller.frame.body)
const pageSize = useSignal(props.controller.pageSize)

// As many skeleton rows as the window had rows, so the height of the table does
// not jump while the next one is on its way; a window that had none — a reset out
// of "Nothing found" — is waiting for a full one (Flow F2).
const skeletonRows = computed(() =>
  rows.value.length > 0 ? rows.value.length : pageSize.value,
)

// The row bars this view draws itself. The table bar is drawn by the room of live
// messages above the rows (HilosTableLive), and the bulk bar lives inside the
// selection panel and is drawn by the bar above the table — anywhere else it would
// take the room the table bar gives to the project.
const rowProgress = useSignal(props.controller.progress.rows)

// признак считает ядро
const paginated = useSignal(props.controller.paginated)

// The fields that wait in a panel instead of taking a column of their own, and the
// columns that are left standing in the row. Every place that measures or draws the
// row itself — the header, the width of a full-row cell, the cells of a row bar —
// counts the second list, while the panel is built from the first.
const detailFields = computed(() => hilosTableDetailFields(frameColumns.value))
const rowColumns = computed(() =>
  frameColumns.value.filter((column) => column.detail !== true),
)

// Which sources went quiet anywhere in the shown window, and which declared columns
// are built from them — the columns whose headers and row cells carry the mark. The
// sentence about them is the room of live messages' own (HilosTableLive).
const staleSources = computed(() => hilosTableStaleSources(rows.value))
const staleColumns = computed(() =>
  hilosTableStaleColumns(frameColumns.value, staleSources.value),
)
const staleColumnKeys = computed(
  () => new Set(staleColumns.value.map((column) => column.key)),
)

// The row-state cell stands while anything waits OR while a shown row's values are
// behind OR while the table declared a field to expand into: it carries all three, and
// a table with no pending change still needs it the moment a source goes quiet or a
// reader is given something to open. Header cell and body cell read the SAME
// condition, so the two cannot drift apart into a row wider than its header.
const markColumn = computed(
  () =>
    pendingCount.value > 0 ||
    staleSources.value.size > 0 ||
    detailFields.value.length > 0,
)

// Every cell that spans the whole row — the placeholder of a removed row, the panel a
// row expands into, the empty and loading states — counts the columns left standing in
// the row plus the mark column while it stands plus the checkbox column while the
// table has marks. This is the ONE place the width is worked out, and everything that
// spans a row reads it rather than counting again.
const bodyColspan = computed(
  () =>
    rowColumns.value.length +
    (markColumn.value ? 1 : 0) +
    (selectionEnabled ? 1 : 0),
)

/** One cell of a row bar's row: how many columns it spans, and whether the bar is under it. */
type ProgressCell = { span: number; covered: boolean }

// The cells of a row bar's row, in the order the columns are declared: runs of
// marked columns merge into one covered cell, runs of unmarked ones into one
// empty cell, and the waiting cell is added exactly while it stands over the
// ordinary rows. No column marked means one covered cell across the whole row.
// The checkbox column takes an empty cell of its own on whichever edge it sits.
//
// The spans add up to bodyColspan by construction, which is what keeps the row
// from growing wider than its header the moment a waiting change appears.
const progressCells = computed<readonly ProgressCell[]>(() => {
  const cells: ProgressCell[] = []
  for (const column of rowColumns.value) {
    const covered = column.progress === true
    const last = cells[cells.length - 1]
    if (last !== undefined && last.covered === covered) {
      last.span += 1
    } else {
      cells.push({ span: 1, covered })
    }
  }
  if (!rowColumns.value.some((column) => column.progress === true)) {
    cells.splice(0, cells.length, {
      span: rowColumns.value.length,
      covered: true,
    })
  }
  if (markColumn.value) {
    cells.push({ span: 1, covered: false })
  }
  if (selectionEnabled) {
    const selectionCell: ProgressCell = { span: 1, covered: false }
    if (selectionEdge === 'start') {
      cells.unshift(selectionCell)
    } else {
      cells.push(selectionCell)
    }
  }

  return cells
})

// The total reads as "at least this many" when the count stopped at its ceiling, which is
// what the trailing plus says. Spelling it out in words would say the same thing longer.
const countLabel = computed(() =>
  totalExact.value ? `${totalCount.value} total` : `${totalCount.value}+ total`,
)

// A row's tint, resolved in the order the mockup resolves it (section 4): amber
// while a pending change waits on the row — a move and a removal alike — and green
// for the couple of seconds after a value landed. Waiting outranks the highlight,
// because it is the one of the two the reader still has to act on. Red belongs to a
// refused write, not to waiting. Grey comes last of the three: amber and green speak
// of something that happened to the row and the reader has yet to take in, while grey
// says only that the reader opened this one themselves. Bootstrap's contextual row
// classes carry their own dark-mode variants, so they adapt to the active theme with
// no custom style layer.
function rowClass(view: TableViewportRow<R>): string | undefined {
  if (view.pending !== null) {
    return 'table-warning'
  }
  if (view.highlighted) {
    return 'table-success'
  }

  return view.expanded ? 'table-active' : undefined
}

// A card's tint, resolved in the order the row's is: amber while a change waits
// on the record, green for the couple of seconds after a value landed, and
// nothing otherwise. It is worn as a border rather than a fill — Bootstrap's
// contextual row classes are built for the cells of a table — and an expanded
// card takes no grey: the row wears grey to tie itself to a panel drawn under it,
// while a card holds its panel inside its own body (Flow F5).
function cardClass(view: TableViewportRow<R>): string | undefined {
  if (view.pending !== null) {
    return 'border-warning'
  }

  return view.highlighted ? 'border-success' : undefined
}

// Whether the page draws anything into a column's cell. A card leaves out the
// place of a column the page filled nothing into: a label with nothing under it
// reads as a value lost rather than as an empty field, and on a phone it spends a
// line of the screen saying that (Flow F3). The row is the other way round — its
// cell always stands, or the row comes out narrower than its header — and that is
// the one place the two branches part company.
function hasCell(column: HilosTableColumn | null): boolean {
  return column !== null && slots[`cell-${column.key}`] !== undefined
}

// The labelled lines of a card: the fields of the layout the page actually drew
// into. A function rather than a computed, because what it reads is the slots and
// those are settled per render — a computed would hold the answer from the render
// the page passed other slots in.
function cardFields(): readonly HilosTableColumn[] {
  return card === null ? [] : card.fields.filter(hasCell)
}

// The id of one row's panel, which the control above it points at through
// aria-controls. One base for the whole table and the row key after it: the keys are
// unique within a window, and two tables on one page mint two bases.
function detailId(rowKey: string): string {
  return `${detailBaseId}-${rowKey}`
}

/** The same for the panel inside the card of that row, off its own base. */
function cardDetailId(rowKey: string): string {
  return `${cardDetailBaseId}-${rowKey}`
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

// The words a frozen header carries for a screen reader: the warning the sort
// control carries, or — on a column that was never sortable — the plain statement
// that its source is behind. The control stays and the warning rides inside it,
// keeping HIL-809's argument about disabled controls as the reason the button is
// not greyed: a disabled button drops out of the focus order, and a warning
// hung on it would never be read to the one reader who needs it most.
function staleColumnText(column: HilosTableColumn): string {
  return column.sortable === true
    ? TABLE_STALENESS_COPY.sortWarning
    : TABLE_STALENESS_COPY.columnMark
}

function onSearchInput(event: Event): void {
  props.controller.setSearch((event.target as HTMLInputElement).value)
}

// The checkbox tells the core the state it is now IN rather than asking it to
// toggle: the state of a checkbox is what the reader sees, and a toggle sent from
// a box the browser has already flipped is a second answer to one question
// (Flow F1).
function onSelectRow(rowKey: string, event: Event): void {
  props.controller.selectRow(rowKey, (event.target as HTMLInputElement).checked)
}

// One input for both directions: the header box goes to "all" out of none and out
// of the half-marked state alike, and clears the window only from "all" — which is
// what the core does with the state the box is now in (Flow F2).
function onSelectPage(event: Event): void {
  props.controller.selectWindow((event.target as HTMLInputElement).checked)
}
</script>

<template>
  <div :data-id="dataId">
    <HilosTableBar
      v-if="declaration"
      :controller="controller"
      :title-id="titleId"
    >
      <template v-if="$slots['bulk-untouched']" #bulk-untouched="untouched">
        <slot name="bulk-untouched" v-bind="untouched" />
      </template>
    </HilosTableBar>

    <!-- The bar a table draws from props, kept while a page still passes them —
    the framework's log pages do. It goes with the props themselves. -->
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

    <!-- Everything live the table has to say — work running over the set, a
    source gone quiet, rows created above the window, changes waiting for Apply —
    in one room that never changes height, outside both epochs of the frame: it
    speaks about what is happening to the rows, not about what the page
    declared. Next to running work, everything a reader sees comes from the page
    through the two slots, because it is the project's business logic and not
    the framework's (mockup section 5, plate 2). -->
    <HilosTableLive :controller="controller" :columns="frameColumns">
      <template #table-progress="progressProps">
        <slot name="table-progress" v-bind="progressProps" />
      </template>
      <template #table-progress-action="progressProps">
        <slot name="table-progress-action" v-bind="progressProps" />
      </template>
    </HilosTableLive>

    <!-- A DECLARED table is a table on a wide screen and a list of cards on a
    narrow one, so its scroll wrapper goes with the table itself: there is
    nothing left to scroll sideways once the columns became lines of a card
    (Design D8). A table still drawn from props has no cards to fall back on and
    keeps the wrapper at every width, scrollbar and all, until its page moves
    onto the declaration. -->
    <div
      class="table-responsive"
      :class="{ 'd-none d-md-block': declaration !== null }"
    >
      <table
        class="table table-striped table-hover align-middle mb-0"
        :aria-labelledby="declaration ? nameId : undefined"
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
            <!-- The checkbox column stands on the edge the installation chose,
            outside the declared columns on either side of them: it belongs to
            the framework, and the page's columns are the page's. -->
            <th
              v-if="selectionEnabled && selectionEdge === 'start'"
              scope="col"
              class="hilos-table-selection-cell"
            >
              <input
                class="form-check-input"
                type="checkbox"
                aria-label="Select all rows on this page"
                data-id="hilos-table-select-page"
                :checked="selectionHeader === 'all'"
                :indeterminate="selectionHeader === 'some'"
                @change="onSelectPage"
              />
            </th>
            <th
              v-for="column in rowColumns"
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
                <template v-if="staleColumnKeys.has(column.key)">
                  <i
                    class="bi bi-snow"
                    :data-id="`hilos-table-stale-column-${column.key}`"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    staleColumnText(column)
                  }}</span>
                </template>
              </button>
              <span
                v-else-if="staleColumnKeys.has(column.key)"
                class="d-inline-flex align-items-center gap-1"
              >
                {{ column.label }}
                <i
                  v-if="sortComponent(column.key) !== undefined"
                  :class="['bi', sortIcon(column.key)]"
                  aria-hidden="true"
                ></i>
                <i
                  class="bi bi-snow"
                  :data-id="`hilos-table-stale-column-${column.key}`"
                  aria-hidden="true"
                ></i>
                <span class="visually-hidden">{{
                  staleColumnText(column)
                }}</span>
              </span>
              <template v-else>{{ column.label }}</template>
            </th>
            <th v-if="markColumn" scope="col" class="text-end">
              <span class="visually-hidden">Row state and controls</span>
            </th>
            <th
              v-if="selectionEnabled && selectionEdge === 'end'"
              scope="col"
              class="hilos-table-selection-cell"
            >
              <input
                class="form-check-input"
                type="checkbox"
                aria-label="Select all rows on this page"
                data-id="hilos-table-select-page"
                :checked="selectionHeader === 'all'"
                :indeterminate="selectionHeader === 'some'"
                @change="onSelectPage"
              />
            </th>
          </tr>
        </thead>
        <!-- The skeleton stands in a body of its own while a window is late:
        a cell for every column standing in the row, the framework's included,
        so the columns keep their widths instead of collapsing into one cell
        and jolting the table sideways on every change (Flow F3). The bars say
        nothing to a screen reader; the hidden line says it in words. -->
        <tbody
          v-if="body === 'loading'"
          aria-busy="true"
          data-id="hilos-table-loading"
        >
          <tr
            v-for="index in skeletonRows"
            :key="index"
            data-id="hilos-table-skeleton-row"
          >
            <td
              v-for="cell in bodyColspan"
              :key="cell"
              class="placeholder-glow"
            >
              <span
                v-if="index === 1 && cell === 1"
                class="visually-hidden"
                role="status"
                >Loading…</span
              >
              <span class="placeholder col-12" aria-hidden="true"></span>
            </td>
          </tr>
        </tbody>
        <tbody v-else>
          <template v-for="view in rows" :key="view.rowKey">
            <tr
              :data-id="`hilos-table-row-${view.rowKey}`"
              :class="rowClass(view)"
            >
              <!-- A row drawn as a placeholder carries no checkbox: there is
              nothing to mark in the trace of a row that left, and the core would
              not take its key anyway (Flow F1). Its own cell spans the whole row,
              so the column is simply not there for it. -->
              <td
                v-if="
                  selectionEnabled &&
                  selectionEdge === 'start' &&
                  !view.placeholder &&
                  view.row !== null
                "
                class="hilos-table-selection-cell"
              >
                <input
                  class="form-check-input"
                  type="checkbox"
                  aria-label="Select row"
                  :data-id="`hilos-table-select-${view.rowKey}`"
                  :checked="view.selected"
                  @change="onSelectRow(view.rowKey, $event)"
                />
              </td>
              <td
                v-if="view.placeholder || view.row === null"
                :colspan="bodyColspan"
                class="text-center text-muted fst-italic"
                data-id="hilos-table-placeholder"
              >
                {{ placeholderText }}
              </td>
              <!-- What stands where a row's values do, one shape per epoch of
              the frame. A DECLARED table hands the page one slot per column and
              writes the cell around it: that is what makes a cell addressable by
              column at all, and it is what lets the card next door be built from
              the same slots instead of a second markup the page would write. A
              table still drawing its frame from props has no column to address a
              cell by, so it keeps handing over the whole row. -->
              <template v-else-if="declaration">
                <!-- The cell stands even where the page filled no slot: a row one
                cell short is a row narrower than its header (Flow F3). -->
                <td
                  v-for="column in rowColumns"
                  :key="column.key"
                  :class="column.cellClass"
                >
                  <slot
                    :name="`cell-${column.key}`"
                    :row="view.row"
                    :row-key="view.rowKey"
                  />
                </td>
              </template>
              <slot v-else name="row" :row="view.row" :row-key="view.rowKey" />
              <td
                v-if="markColumn && !view.placeholder && view.row !== null"
                class="text-end text-nowrap"
              >
                <!-- The freshness mark comes first and the waiting badge after
                it: a row can both wait and stand on values that are behind, and
                neither statement stands in for the other. -->
                <template v-if="view.staleSources.length > 0">
                  <i
                    class="bi bi-snow me-1"
                    :data-id="`hilos-table-stale-row-${view.rowKey}`"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    TABLE_STALENESS_COPY.rowMark
                  }}</span>
                </template>
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
                <!-- The control comes last of the three and stands at the very
                edge: the snowflake and the badge STATE something about the row,
                while this one is the only thing in the cell the reader acts on. -->
                <button
                  v-if="detailFields.length > 0"
                  type="button"
                  class="btn btn-sm btn-outline-secondary ms-1"
                  :data-id="`hilos-table-expand-${view.rowKey}`"
                  :aria-expanded="view.expanded"
                  :aria-controls="detailId(view.rowKey)"
                  @click="controller.expandRow(view.rowKey, !view.expanded)"
                >
                  <i
                    :class="[
                      'bi',
                      view.expanded ? 'bi-chevron-up' : 'bi-chevron-down',
                    ]"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    view.expanded
                      ? TABLE_DETAIL_COPY.hide
                      : TABLE_DETAIL_COPY.show
                  }}</span>
                </button>
              </td>
              <td
                v-if="
                  selectionEnabled &&
                  selectionEdge === 'end' &&
                  !view.placeholder &&
                  view.row !== null
                "
                class="hilos-table-selection-cell"
              >
                <input
                  class="form-check-input"
                  type="checkbox"
                  aria-label="Select row"
                  :data-id="`hilos-table-select-${view.rowKey}`"
                  :checked="view.selected"
                  @change="onSelectRow(view.rowKey, $event)"
                />
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

            <!-- The panel this row expands into, drawn after the row's own bar:
            the bar is a continuation of the row it belongs to, and what the
            reader opened themselves comes after what is happening to the record
            on its own. A placeholder never says it is expanded; the second half
            of the condition says the same thing to the compiler, so the fields
            below are handed a row rather than a row-or-nothing. -->
            <tr
              v-if="view.expanded && view.row !== null"
              :id="detailId(view.rowKey)"
              class="table-active"
              :data-id="`hilos-table-row-detail-${view.rowKey}`"
            >
              <td :colspan="bodyColspan" class="pt-0">
                <dl class="row row-cols-1 row-cols-md-3 g-2 mb-0 small">
                  <div
                    v-for="field in detailFields"
                    :key="field.key"
                    class="col"
                  >
                    <dt class="text-body-secondary fw-normal">
                      {{ field.label }}
                    </dt>
                    <dd class="mb-0 text-break">
                      <!-- A field the page declared but drew nothing into shows
                      the dash its cells show, rather than an empty line that
                      would read as "there is no value". -->
                      <slot
                        :name="`detail-${field.key}`"
                        :row="view.row"
                        :row-key="view.rowKey"
                        >{{ TABLE_DETAIL_COPY.empty }}</slot
                      >
                    </dd>
                  </div>
                </dl>
              </td>
            </tr>
          </template>
          <tr v-if="body !== 'rows'">
            <td :colspan="bodyColspan">
              <HilosTableEmptyState
                :controller="controller"
                :kind="
                  body === 'empty_filtered' || body === 'empty_page'
                    ? body
                    : 'empty'
                "
              >
                <slot name="empty">{{ emptyText }}</slot>
              </HilosTableEmptyState>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- The same rows as cards, the shape a table takes on a narrow screen: the
    framework builds each card out of the very columns the page declared, so no
    project writes a second markup for its table (mockup section 9). Both
    branches stand in the document at once and Bootstrap's visibility utilities
    show exactly one of them — there is no width at which both are seen, and
    crossing the boundary re-renders nothing, both being mounted already
    (Flow F11). The price is that a row's data-id is in the document twice, which
    is why a test on a narrow screen aims at a row THROUGH this container
    (table-subscription.md, the registry of selectors). The cards are a list and
    carry the accessible name of the table itself: the same name rather than a
    second one, this being the same table, and never both at once — the branch
    that is hidden leaves the accessibility tree with its display (Flow F9). The
    list holds the cards and nothing else; what the table says in words when it
    has no rows stands BESIDE it, a sentence not being an item of a list. -->
    <div v-if="card" class="d-md-none" data-id="hilos-table-cards">
      <div v-if="body === 'rows'" role="list" :aria-labelledby="nameId">
        <div
          v-for="view in rows"
          :key="view.rowKey"
          class="card mb-2"
          :class="cardClass(view)"
          role="listitem"
          :data-id="`hilos-table-card-${view.rowKey}`"
        >
          <!-- A removed row keeps its place as a card of one line, exactly as
          it keeps it as a row of one cell: the set never closes up under the
          reader (Flow F4). -->
          <div
            v-if="view.placeholder || view.row === null"
            class="card-body py-2 px-3 text-center text-body-secondary fst-italic small"
            data-id="hilos-table-placeholder"
          >
            {{ placeholderText }}
          </div>
          <div v-else class="card-body py-2 px-3">
            <div class="d-flex align-items-start gap-2 mb-1">
              <!-- The mark sits on the edge the installation chose, the same
              edge it sits on in the row: one product disagreeing with itself
              between its table and its card is the very thing that choice
              forbids. -->
              <input
                v-if="selectionEnabled && selectionEdge === 'start'"
                class="form-check-input mt-1"
                type="checkbox"
                aria-label="Select row"
                :data-id="`hilos-table-select-${view.rowKey}`"
                :checked="view.selected"
                @change="onSelectRow(view.rowKey, $event)"
              />
              <span v-if="hasCell(card.title)" class="fw-medium">
                <slot
                  :name="`cell-${card.title!.key}`"
                  :row="view.row"
                  :row-key="view.rowKey"
                />
              </span>
              <!-- The right of the head, in one group: what the PAGE says about
              the row first, then what the FRAMEWORK says about it. The page's
              badge is not pushed out by a framework mark — on a wide screen the
              two stand in two different cells, and a card is a second
              projection of the same columns rather than a smaller set of facts
              (Design D9, design debt D-058). -->
              <span class="ms-auto d-flex align-items-center gap-1">
                <slot
                  v-if="hasCell(card.badge)"
                  :name="`cell-${card.badge!.key}`"
                  :row="view.row"
                  :row-key="view.rowKey"
                />
                <template v-if="view.staleSources.length > 0">
                  <i
                    class="bi bi-snow"
                    :data-id="`hilos-table-stale-row-${view.rowKey}`"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    TABLE_STALENESS_COPY.rowMark
                  }}</span>
                </template>
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
                <!-- The control comes after everything that merely STATES
                something about the record, exactly as it does at the end of a
                row: it is the one thing in the head the reader acts on. -->
                <button
                  v-if="detailFields.length > 0"
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  :data-id="`hilos-table-expand-${view.rowKey}`"
                  :aria-expanded="view.expanded"
                  :aria-controls="cardDetailId(view.rowKey)"
                  @click="controller.expandRow(view.rowKey, !view.expanded)"
                >
                  <i
                    :class="[
                      'bi',
                      view.expanded ? 'bi-chevron-up' : 'bi-chevron-down',
                    ]"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    view.expanded
                      ? TABLE_DETAIL_COPY.hide
                      : TABLE_DETAIL_COPY.show
                  }}</span>
                </button>
                <input
                  v-if="selectionEnabled && selectionEdge === 'end'"
                  class="form-check-input mt-1"
                  type="checkbox"
                  aria-label="Select row"
                  :data-id="`hilos-table-select-${view.rowKey}`"
                  :checked="view.selected"
                  @change="onSelectRow(view.rowKey, $event)"
                />
              </span>
            </div>

            <!-- What a column becomes on a narrow screen is a pair of a label
            and a value, and a description list is the one markup that says so
            to a screen reader (Flow F10). -->
            <dl v-if="cardFields().length > 0" class="row mb-2 small g-0">
              <template v-for="field in cardFields()" :key="field.key">
                <dt class="col-5 fw-normal text-body-secondary">
                  {{ field.label }}
                </dt>
                <dd class="col-7 mb-0">
                  <slot
                    :name="`cell-${field.key}`"
                    :row="view.row"
                    :row-key="view.rowKey"
                  />
                </dd>
              </template>
            </dl>

            <!-- What the reader opened, going on with the very pairs of label
            and value the fields above are: in a row the panel comes last of
            all, under the bar of the row's own work, but in a card the controls
            and that bar are the bottom block and the panel belongs with the
            body. -->
            <div
              v-if="view.expanded"
              :id="cardDetailId(view.rowKey)"
              class="mb-2"
              :data-id="`hilos-table-row-detail-${view.rowKey}`"
            >
              <dl class="row mb-0 small g-0">
                <template v-for="field in detailFields" :key="field.key">
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ field.label }}
                  </dt>
                  <dd class="col-7 mb-0 text-break">
                    <!-- A field the page declared but drew nothing into shows
                    the dash its cells show, rather than an empty line that
                    would read as "there is no value". -->
                    <slot
                      :name="`detail-${field.key}`"
                      :row="view.row"
                      :row-key="view.rowKey"
                      >{{ TABLE_DETAIL_COPY.empty }}</slot
                    >
                  </dd>
                </template>
              </dl>
            </div>

            <!-- The controls of the row, full width at the foot of the card.
            Which of them comes first is the markup the page hands over, and the
            framework neither reorders them nor takes one away (Flow F2). -->
            <div v-if="hasCell(card.actions)" class="d-grid gap-2">
              <slot
                :name="`cell-${card.actions!.key}`"
                :row="view.row"
                :row-key="view.rowKey"
              />
            </div>

            <!-- Work running over this one record, at the very bottom of the
            card and across its whole width: which columns a bar stretches under
            says nothing here, a card having no columns standing in a row (Flow
            F7). -->
            <div
              v-if="rowBar(view.rowKey) !== undefined"
              class="mt-2"
              :data-id="`hilos-table-progress-row-${view.rowKey}`"
            >
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
            </div>
          </div>
        </div>
      </div>

      <!-- The skeleton and the three states a table says in words. They live
      inside the table in the wide branch, so a narrow screen would hide them
      along with it and the phone would be left with a blank space where they
      are (Flow F12). A card of the skeleton is one bar, as the mockup draws it.
      The page writes the empty slot once and sees it in both branches. -->
      <div
        v-else-if="body === 'loading'"
        aria-busy="true"
        data-id="hilos-table-loading"
      >
        <span class="visually-hidden" role="status">Loading…</span>
        <div
          v-for="index in skeletonRows"
          :key="index"
          class="card mb-2"
          aria-hidden="true"
          data-id="hilos-table-skeleton-row"
        >
          <div class="card-body py-2 px-3 placeholder-glow">
            <span class="placeholder col-12"></span>
          </div>
        </div>
      </div>
      <HilosTableEmptyState
        v-else
        :controller="controller"
        :kind="
          body === 'empty_filtered' || body === 'empty_page' ? body : 'empty'
        "
      >
        <slot name="empty">{{ emptyText }}</slot>
      </HilosTableEmptyState>
    </div>

    <HilosTableFooter v-if="declaration" :controller="controller" />

    <!-- The footer a table draws from its own comparisons, kept for the same
    reason and going the same way as the bar above. -->
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
          :disabled="!hasPreviousPage"
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
