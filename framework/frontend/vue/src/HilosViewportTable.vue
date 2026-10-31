<!-- HilosViewportTable — the thin Vue view over the SERVER-WINDOWED
TableViewportController. Search, sort, and paging change the viewport descriptor
and are sent to the backend (NO local filtering); live changes arrive as pending
and are resolved with the Apply button. A removed row renders as a placeholder in
its slot until the window changes. It holds NO table logic
(multiframework-core.md): the controller owns the descriptor, pending, and Apply.
Body cells come from a `#cell-<key>` slot per declared column — the page gives
the content, the framework writes the cell — plus one framework-owned cell at the end
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
On a narrow screen a table is drawn as a list of cards instead: the
framework builds each card out of the very columns the page declared and fills
it from the same `#cell-<key>` slots, so a project writes no second markup for
its phone (mockups/components/table section 9). A card opens into its own panel,
inside its own body and off an id base of its own. Both branches stand in the
document and Bootstrap's visibility utilities show one of them.
It draws HilosTableBar and HilosTableFooter from the page's HilosTableFrame.
The body is drawn from the state the core decides (HilosTableBody): rows, a
skeleton of rows while a window change is late, or one of the four worded states
drawn by HilosTableEmptyState — the page's own "nothing here yet", the
framework's "Nothing found", and "List unavailable" (mockups/components/table section 10). -->

<script setup lang="ts" generic="R">
import {
  computed,
  effectScope,
  inject,
  onMounted,
  onScopeDispose,
  shallowRef,
  useId,
  useSlots,
  watch,
} from 'vue'
import {
  TABLE_DETAIL_COPY,
  TABLE_STALENESS_COPY,
  HILOS_TABLE_ACTIONS_KEY,
  bindTableViewPreference,
  hilosTableDetailFields,
  hilosTableOrderPosition,
  hilosTablePlaceholder,
  hilosTableRowMessageCells,
  hilosTableSortPositionLabel,
  hilosTableStaleColumns,
  hilosTableStaleSources,
} from '@hilos/core/base'
import type {
  HilosTableColumn,
  // Aliased because the component drawing one of these carries the same name.
  HilosTableProgress as TableProgressBar,
  HilosTableRowMessage,
  HilosTableRowMessageCell,
  TableSort,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core/base'

import HilosTableBar from './HilosTableBar.vue'
import HilosTableEmptyState from './HilosTableEmptyState.vue'
import HilosTableFooter from './HilosTableFooter.vue'
import HilosTableLive from './HilosTableLive.vue'
import HilosTableProgress from './HilosTableProgress.vue'
import HilosTableRecordCard from './HilosTableRecordCard.vue'
import HilosTableValue from './HilosTableValue.vue'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'
import { hilosTableSelectionEdgeKey } from './hilosTableSelectionEdge.js'
import { useSignal } from './useSignal.js'
import { useTableViewportGeometry } from './useTableViewportGeometry.js'

const props = withDefaults(
  defineProps<{
    /** The headless server-windowed controller driving rows, descriptor, and pending. */
    controller: TableViewportController<R>
    /** Whether the search box owns focus when this table opens inside a modal. */
    autofocusSearch?: boolean
    /**
     * The `data-id` the table's root carries, for a page that draws more than one of
     * them: the default is the shared handle, and a second table on the same page names
     * itself so the two can be told apart from outside.
     */
    dataId?: string
  }>(),
  {
    autofocusSearch: false,
    dataId: 'hilos-viewport-table',
  },
)

// Every mirror belongs to the current controller. The shared signal adapter
// already supplies scope cleanup; replacing this scope releases the old owner.
function mirrors() {
  const controller = props.controller
  return {
    frameColumns: useSignal(controller.frame.columns),
    card: useSignal(controller.frame.card),
    view: useSignal(controller.frame.view),
    tileCard: useSignal(controller.frame.tileCard),
    imageColumn: useSignal(controller.frame.imageColumn),
    selectionHeader: useSignal(controller.selection.header),
    rows: useSignal(controller.rows),
    order: useSignal(controller.order),
    body: useSignal(controller.frame.body),
    controlsAvailable: useSignal(controller.windowControlsAvailable),
    pageSize: useSignal(controller.pageSize),
    rowProgress: useSignal(controller.progress.rows),
  }
}
let mirrorScope = effectScope()
const current = shallowRef(mirrorScope.run(mirrors)!)
const controllerMount = shallowRef(0)
watch(
  () => props.controller,
  () => {
    mirrorScope.stop()
    mirrorScope = effectScope()
    current.value = mirrorScope.run(mirrors)!
    controllerMount.value++
  },
  { flush: 'sync' },
)
onScopeDispose(() => mirrorScope.stop())
const declaration = computed(() => props.controller.frame.declaration)
const frameColumns = computed(() => current.value.frameColumns.value)
const tableView = computed(() => current.value.view.value)
const tileCard = computed(() => current.value.tileCard.value)
const imageColumn = computed(() => current.value.imageColumn.value)

// Persistence is a browser effect of this mount, with the same owner lifetime as
// its mirrors. The core restores before subscribing and owns erase/reset policy.
let mounted = false
let releaseViewPreference: (() => void) | undefined
function bindViewPreference(): void {
  releaseViewPreference?.()
  releaseViewPreference = undefined
  if (mounted && props.controller.frame.declaration.views)
    releaseViewPreference = bindTableViewPreference(props.controller)
}
onMounted(() => {
  mounted = true
  bindViewPreference()
})
watch(() => props.controller, bindViewPreference, { flush: 'sync' })
onScopeDispose(() => {
  mounted = false
  releaseViewPreference?.()
})

// Which declared column takes which place of the card a row is drawn as on a
// narrow screen — the head, the badge beside it, the labelled lines, the
// controls. The core derived it from the declaration (tableCard.ts) and the view
// has no arithmetic of its own about it; like the declaration it follows from, it
// changes with the resolved columns.
const card = computed(() => current.value.card.value)

// The slots as the page filled them — a missing renderer keeps its wide cell
// but needs no value wrapper. The card renderer checks its forwarded slots.
const slots = useSlots()

// The declared title names the table through aria-labelledby, so the id is
// minted here — where both the bar that renders the heading and the table that
// points at it can see it (Flow F9).
const titleId = useId()

// What names a table: its own title when it declared one, and otherwise the
// heading of the page it stands on, which already names it (Design D3). Undefined for a
// table that declared neither and stands outside an admin page — it has no name to take.
const pageHeadingId = inject(hilosPageHeadingIdKey, undefined)
const nameId = computed(() =>
  declaration.value.title ? titleId : pageHeadingId,
)

// The base every expanded row's panel takes its id from — minted the same way the
// title above is, and for the same reason: the control that points at a panel and
// the panel itself are drawn in two places of one template.
const detailBaseId = useId()

// The base the panel inside a CARD takes its id from — a second one, minted for
// the same table. An id is unique in a document and both branches stand in it at
// once, so a card borrowing the row's id would put that id in twice and break the
// tie between control and panel on both.
const cardDetailBaseId = useId()
const tileDetailBaseId = useId()

// Which edge the checkbox column sits on — one choice for the whole installation
// and not a prop of this table, because two tables of one product disagreeing
// about it is the very thing the rule forbids (Design D5). A project that
// provided nothing gets the left edge, where lists usually keep it.
const selectionEdge = inject(hilosTableSelectionEdgeKey, 'start')

// The marks, and the one sign that this table has them: a page that declared bulk
// operations (Flow F14).
const selectionEnabled = computed(() => props.controller.selection.enabled)
const selectionHeader = computed(() => current.value.selectionHeader.value)

const rows = computed(() => current.value.rows.value)
const order = computed(() => current.value.order.value)

// Which state the body is in — rows, the skeleton, or one of the four worded
// states. The core decides it (tableFrame.ts, HilosTableBody) so that the three
// view layers cannot decide it three ways, and both branches below read this one
// answer.
const body = computed(() => current.value.body.value)
const controlsAvailable = computed(() => current.value.controlsAvailable.value)
const pageSize = computed(() => current.value.pageSize.value)

// As many skeleton rows as the window had rows, so the height of the table does
// not jump while the next one is on its way; a window that had none — a reset out
// of "Nothing found" — is waiting for a full one (Flow F2).
const skeletonRows = computed(() =>
  rows.value.length > 0 ? rows.value.length : pageSize.value,
)

// The row bars this view draws itself. The table bar and the bulk bar are drawn by
// the room of live messages above the rows (HilosTableLive).
const rowProgress = computed(() => current.value.rowProgress.value)

// The fields that wait in a panel instead of taking a column of their own, and the
// columns that are left standing in the row. Every place that measures or draws the
// row itself — the header, the width of a full-row cell, the cells of a row bar —
// counts the second list, while the panel is built from the first.
const detailFields = computed(() => hilosTableDetailFields(frameColumns.value))
const rowColumns = computed(() =>
  frameColumns.value.filter((column) => column.detail !== true),
)
const columnMap = computed(
  () => new Map(frameColumns.value.map((column) => [column.key, column])),
)

function columnByKey(key: string): HilosTableColumn | undefined {
  return columnMap.value.get(key)
}

function rowCells(
  message: HilosTableRowMessage | null,
): readonly HilosTableRowMessageCell[] {
  return hilosTableRowMessageCells(frameColumns.value, message)
}

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

// Every cell that spans the whole row — the placeholder of a removed row, the panel a
// row expands into, the empty and loading states — counts the columns left standing in
// the row plus the permanent state column plus the checkbox column while the
// table has marks. This is the ONE place the width is worked out, and everything that
// spans a row reads it rather than counting again.
const bodyColspan = computed(
  () => rowColumns.value.length + 1 + (selectionEnabled.value ? 1 : 0),
)

const root = shallowRef<HTMLElement | null>(null)
const table = shallowRef<HTMLTableElement | null>(null)
const columnKeys = computed(() => {
  const keys = [
    ...rowColumns.value.map((column) => `column:${column.key}`),
    'state',
  ]
  if (selectionEnabled.value) {
    if (selectionEdge === 'start') keys.unshift('selection')
    else keys.push('selection')
  }
  return keys
})
const geometry = useTableViewportGeometry(
  () => props.controller.geometry,
  root,
  table,
  () => columnKeys.value,
  (record) => props.controller.isTextRecordCurrent(record),
)
watch(frameColumns, geometry.layoutChanged)
watch(tableView, geometry.layoutChanged)
const tracks = computed(() => geometry.tracks.value)
const trackWidths = computed(() => geometry.trackWidths.value)
const reasonFloors = computed(() => geometry.reasonFloors.value)

/** One cell of a row bar's row: how many columns it spans, and whether the bar is under it. */
type ProgressCell = { span: number; covered: boolean }

// The cells of a row bar's row, in the order the columns are declared: runs of
// marked columns merge into one covered cell, runs of unmarked ones into one
// empty cell, followed by the permanent state cell. No column marked means one
// covered cell across the declared row columns.
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
  cells.push({ span: 1, covered: false })
  if (selectionEnabled.value) {
    const selectionCell: ProgressCell = { span: 1, covered: false }
    if (selectionEdge === 'start') {
      cells.unshift(selectionCell)
    } else {
      cells.push(selectionCell)
    }
  }

  return cells
})

// A row's tint, resolved in the order the mockup resolves it (section 4): amber
// while a pending change waits on the row — a move and a removal alike — and green
// for the couple of seconds after a value landed. Waiting outranks the highlight,
// because it is the one of the two the reader still has to act on. Red marks a
// record that stands refused — a failed backup or an unacknowledged write that
// refused to apply; it sits behind a fresh highlight so the reader first sees that
// a change arrived, but outranks the opened row's grey because refusal is a state
// of the record itself rather than the reader's view over it. Grey comes last of
// them: amber, green, and red speak of something that happened to the record and
// the reader has yet to take in, while grey says only that the reader opened this
// one themselves. Bootstrap's contextual row fills stay light in dark mode, with
// their own dark text keeping them readable. Keep the stock Bootstrap colors
// intentionally: Hilos has no palette (HIL-1295).
function rowClass(view: TableViewportRow<R>): string | undefined {
  if (view.pending !== null) {
    return 'table-warning'
  }
  if (view.highlighted) {
    return 'table-success'
  }
  if (view.refused) {
    return 'table-danger'
  }

  return view.expanded ? 'table-active' : undefined
}

// Keep every wide cell standing, even when its slot has no renderer, so the row
// stays as wide as its header. Only a filled slot needs HilosTableValue.
function hasCell(column: HilosTableColumn | null): boolean {
  return column !== null && slots[`cell-${column.key}`] !== undefined
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
  <div :key="controllerMount" ref="root" :data-id="dataId" tabindex="-1">
    <HilosTableBar
      :key="controllerMount"
      :controller="controller"
      :title-id="titleId"
      :autofocus-search="autofocusSearch"
    />

    <!-- Everything live the table has to say — work running over the set, a
    source gone quiet, new rows the window cannot show, changes waiting for Apply —
    in one room that never changes height: it
    speaks about what is happening to the rows, not about what the page
    declared. Next to running work, everything a reader sees comes from the page
    through the two slots, because it is the project's business logic and not
    the framework's (mockup section 5, plate 2). -->
    <HilosTableLive
      :key="controllerMount"
      :controller="controller"
      :columns="frameColumns"
    >
      <template #table-progress="progressProps">
        <slot name="table-progress" v-bind="progressProps" />
      </template>
      <template #table-progress-action="progressProps">
        <slot name="table-progress-action" v-bind="progressProps" />
      </template>
      <template
        v-if="$slots['table-progress-announcement']"
        #table-progress-announcement="progressProps"
      >
        <slot name="table-progress-announcement" v-bind="progressProps" />
      </template>
      <template v-if="$slots['bulk-untouched']" #bulk-untouched="untouched">
        <slot name="bulk-untouched" v-bind="untouched" />
      </template>
    </HilosTableLive>

    <!-- The scroll wrapper belongs to the wide table: once columns become
    lines of a card, there is nothing left to scroll sideways (Design D8). -->
    <div
      class="table-responsive position-relative"
      :class="tableView === 'tiles' ? 'd-none' : 'd-none d-md-block'"
    >
      <div
        class="table hilos-table-room hilos-table-room-wide invisible"
        aria-hidden="true"
        inert
        :style="{ '--hilos-table-width-px': tracks?.tableWidth }"
      >
        <div class="hilos-table-replacement-body">
          <div class="hilos-table-replacement-row">
            <div class="hilos-table-replacement-cell">
              <div
                class="hilos-table-reason-twins hilos-stack text-center fst-italic"
              >
                <span
                  v-for="reason in [
                    'deleted',
                    'left_set',
                    'moved_out',
                  ] as const"
                  :key="reason"
                  ><i
                    :class="`bi ${hilosTablePlaceholder(reason).icon} me-1`"
                  ></i
                  >{{ hilosTablePlaceholder(reason).text }}</span
                >
              </div>
            </div>
          </div>
        </div>
        <div class="hilos-table-fallback"></div>
      </div>
      <table
        ref="table"
        class="table table-striped table-hover align-middle mb-0"
        :class="{ 'hilos-table-fixed': tracks !== null }"
        :style="{
          '--hilos-table-width-px': tracks?.tableWidth,
          '--hilos-table-reason-px': reasonFloors.wide,
        }"
        :aria-labelledby="nameId"
      >
        <colgroup>
          <col
            v-for="(key, index) in columnKeys"
            :key="key"
            class="hilos-table-track"
            :style="{ '--hilos-table-track-px': trackWidths[index] }"
          />
        </colgroup>
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
                :disabled="!controlsAvailable"
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
                <span v-if="column.source !== undefined" class="hilos-stack">
                  <i class="bi bi-snow invisible" aria-hidden="true" inert></i>
                  <i
                    v-if="staleColumnKeys.has(column.key)"
                    class="bi bi-snow"
                    :data-id="`hilos-table-stale-column-${column.key}`"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    staleColumnKeys.has(column.key)
                      ? staleColumnText(column)
                      : ''
                  }}</span>
                </span>
              </button>
              <span v-else class="d-inline-flex align-items-center gap-1">
                {{ column.label }}
                <i
                  v-if="sortComponent(column.key) !== undefined"
                  :class="['bi', sortIcon(column.key)]"
                  aria-hidden="true"
                ></i>
                <span v-if="column.source !== undefined" class="hilos-stack">
                  <i class="bi bi-snow invisible" aria-hidden="true" inert></i>
                  <i
                    v-if="staleColumnKeys.has(column.key)"
                    class="bi bi-snow"
                    :data-id="`hilos-table-stale-column-${column.key}`"
                    aria-hidden="true"
                  ></i>
                  <span class="visually-hidden">{{
                    staleColumnKeys.has(column.key)
                      ? staleColumnText(column)
                      : ''
                  }}</span>
                </span>
              </span>
            </th>
            <th scope="col" class="text-end text-nowrap">
              <span
                class="hilos-table-marks invisible"
                aria-hidden="true"
                inert
              >
                <i class="bi bi-snow"></i>
                <span class="hilos-stack">
                  <span
                    class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                    ><i class="bi bi-arrows-move"></i> Will move</span
                  >
                  <span
                    class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                    ><i class="bi bi-box-arrow-right"></i> Will leave</span
                  >
                </span>
                <span
                  v-if="detailFields.length > 0"
                  class="btn btn-sm btn-outline-secondary"
                  ><i class="bi bi-chevron-down"></i
                ></span>
              </span>
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
          <template v-for="view in rows" :key="view.occurrence">
            <tr
              :ref="
                (el) =>
                  geometry.bind(
                    view.rowKey,
                    view.occurrence,
                    'wide',
                    'main',
                    el,
                  )
              "
              :data-id="`hilos-table-row-${view.rowKey}`"
              :class="[
                rowClass(view),
                { 'hilos-table-main': !view.placeholder },
              ]"
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
                class="hilos-table-placeholder-cell text-center text-body-secondary fst-italic"
                data-id="hilos-table-placeholder"
              >
                <div
                  class="hilos-table-record-space"
                  :style="{
                    '--hilos-table-record-px': geometry.recordPixels(
                      view.rowKey,
                      view.occurrence,
                      'wide',
                    ),
                  }"
                >
                  <span>
                    <i
                      :class="`bi ${hilosTablePlaceholder(view.removal).icon} me-1`"
                      aria-hidden="true"
                    ></i>
                    {{ hilosTablePlaceholder(view.removal).text }}
                  </span>
                </div>
              </td>
              <template v-else>
                <!-- Ordinary cells before the tail and the merged tail starting from
                fromColumn when the row carries a message (mockups/admin/maintenance). -->
                <template
                  v-for="cell in rowCells(view.rowMessage)"
                  :key="cell.key"
                >
                  <td
                    v-if="cell.text !== null"
                    :colspan="cell.span"
                    class="text-body-secondary fst-italic"
                    data-id="hilos-table-row-message"
                  >
                    {{ cell.text }}
                  </td>
                  <td
                    v-else-if="columnByKey(cell.key)"
                    :class="columnByKey(cell.key)!.cellClass"
                  >
                    <span
                      v-if="cell.key === HILOS_TABLE_ACTIONS_KEY"
                      class="d-inline-flex align-items-center gap-1"
                    >
                      <slot
                        :name="`cell-${cell.key}`"
                        :row="view.row"
                        :row-key="view.rowKey"
                      />
                    </span>
                    <HilosTableValue
                      v-else-if="hasCell(columnByKey(cell.key)!)"
                      :key="columnByKey(cell.key)!.heightContent"
                      :controller="props.controller"
                      :read-record="
                        geometry.recordReader(
                          view.rowKey,
                          view.occurrence,
                          'wide',
                        )
                      "
                      :column-key="cell.key"
                      :label="columnByKey(cell.key)!.label"
                      :height-content="columnByKey(cell.key)!.heightContent"
                      projection="wide"
                    >
                      <slot
                        :name="`cell-${cell.key}`"
                        :row="view.row"
                        :row-key="view.rowKey"
                      />
                    </HilosTableValue>
                  </td>
                </template>
              </template>
              <td
                v-if="!view.placeholder && view.row !== null"
                class="text-end text-nowrap"
              >
                <span class="hilos-stack">
                  <span
                    class="hilos-table-marks invisible"
                    aria-hidden="true"
                    inert
                  >
                    <i class="bi bi-snow"></i>
                    <span class="hilos-stack">
                      <span
                        class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                        ><i class="bi bi-arrows-move"></i> Will move</span
                      >
                      <span
                        class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                        ><i class="bi bi-box-arrow-right"></i> Will leave</span
                      >
                    </span>
                    <span
                      v-if="detailFields.length > 0"
                      class="btn btn-sm btn-outline-secondary"
                      ><i class="bi bi-chevron-down"></i
                    ></span>
                  </span>
                  <span class="hilos-table-marks">
                    <!-- The freshness mark comes first and the waiting badge after
                it: a row can both wait and stand on values that are behind, and
                neither statement stands in for the other. -->
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
                      <i class="bi bi-arrows-move" aria-hidden="true"></i> Will
                      move
                    </span>
                    <span
                      v-else-if="view.pending === 'remove'"
                      class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                      :data-id="`hilos-table-pending-remove-${view.rowKey}`"
                    >
                      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                      Will leave
                    </span>
                    <!-- The control comes last of the three and stands at the very
                edge: the snowflake and the badge STATE something about the row,
                while this one is the only thing in the cell the reader acts on. -->
                    <button
                      v-if="detailFields.length > 0 && view.expandable"
                      type="button"
                      class="btn btn-sm btn-outline-secondary ms-auto"
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
                  </span>
                </span>
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
              :ref="
                (el) =>
                  geometry.bind(
                    view.rowKey,
                    view.occurrence,
                    'wide',
                    'work',
                    el,
                  )
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
            <tr
              v-else-if="
                !view.placeholder &&
                geometry.workPixels(view.rowKey, 'wide') > 0
              "
              :ref="
                (el) =>
                  geometry.bind(
                    view.rowKey,
                    view.occurrence,
                    'wide',
                    'work',
                    el,
                  )
              "
              class="hilos-table-work-reserve"
              aria-hidden="true"
              inert
            >
              <td :colspan="bodyColspan" class="hilos-table-placeholder-cell">
                <div
                  class="hilos-table-work-space"
                  :style="{
                    '--hilos-table-work-px': geometry.workPixels(
                      view.rowKey,
                      'wide',
                    ),
                  }"
                ></div>
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
              :ref="
                (el) =>
                  geometry.bind(
                    view.rowKey,
                    view.occurrence,
                    'wide',
                    'detail',
                    el,
                  )
              "
              class="table-active"
              :data-id="`hilos-table-row-detail-${view.rowKey}`"
            >
              <td :colspan="bodyColspan" class="pt-0">
                <dl class="row row-cols-1 row-cols-md-3 g-2 mb-0 small">
                  <!-- A wide field takes the whole row of the grid on every
                  screen: col-md-12 is declared after row-cols-md-3 and wins
                  over it, so no style of our own is needed. -->
                  <div
                    v-for="field in detailFields"
                    :key="field.key"
                    :class="['col', { 'col-md-12': field.detailWide === true }]"
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
                  (body === 'empty_filtered' &&
                    declaration.filteredEmpty !== 'page') ||
                  body === 'empty_page' ||
                  body === 'unavailable'
                    ? body
                    : 'empty'
                "
              >
                <slot name="empty">No rows.</slot>
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
    <div
      class="position-relative"
      :class="tableView === 'tiles' ? 'd-none' : 'd-md-none'"
      data-id="hilos-table-cards"
    >
      <div
        class="hilos-table-room hilos-table-room-card invisible card mb-2"
        aria-hidden="true"
        inert
      >
        <div class="card-body py-2 px-3">
          <div
            class="hilos-table-reason-twins hilos-stack text-center fst-italic small"
          >
            <span
              v-for="reason in ['deleted', 'left_set', 'moved_out'] as const"
              :key="reason"
              ><i :class="`bi ${hilosTablePlaceholder(reason).icon} me-1`"></i
              >{{ hilosTablePlaceholder(reason).text }}</span
            >
          </div>
          <div class="hilos-table-fallback"></div>
        </div>
      </div>
      <div v-if="body === 'rows'" role="list" :aria-labelledby="nameId">
        <HilosTableRecordCard
          v-for="view in rows"
          :key="view.occurrence"
          :controller="controller"
          :view="view"
          :card="card"
          :columns="frameColumns"
          :detail-fields="detailFields"
          :detail-id="cardDetailId(view.rowKey)"
          projection="card"
          :geometry="geometry"
          :reason-floor="reasonFloors.card"
          :selection-enabled="selectionEnabled"
          :selection-edge="selectionEdge"
          :progress="rowBar(view.rowKey)"
        >
          <template v-for="(_, name) in $slots" #[name]="slotProps">
            <slot :name="name" v-bind="slotProps" />
          </template>
        </HilosTableRecordCard>
      </div>

      <!-- The skeleton and the four states a table says in words. They live
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
          (body === 'empty_filtered' && declaration.filteredEmpty !== 'page') ||
          body === 'empty_page' ||
          body === 'unavailable'
            ? body
            : 'empty'
        "
      >
        <slot name="empty">No rows.</slot>
      </HilosTableEmptyState>
    </div>

    <div
      v-if="declaration.views"
      class="position-relative"
      :class="{ 'd-none': tableView !== 'tiles' }"
      data-id="hilos-table-tiles"
      @load.capture="geometry.layoutChanged()"
    >
      <!-- A framework-only twin has the same Bootstrap column width and shell.
      It never holds a picture, a row value, or an interactive clone. -->
      <div class="hilos-table-room invisible" aria-hidden="true" inert>
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">
          <div class="col">
            <div class="card hilos-table-tile hilos-table-room-tiles">
              <div class="card-body py-2 px-3">
                <div
                  class="hilos-table-reason-twins hilos-stack text-center fst-italic small"
                >
                  <span
                    v-for="reason in [
                      'deleted',
                      'left_set',
                      'moved_out',
                    ] as const"
                    :key="reason"
                    ><i
                      :class="`bi ${hilosTablePlaceholder(reason).icon} me-1`"
                    ></i
                    >{{ hilosTablePlaceholder(reason).text }}</span
                  >
                </div>
                <div class="hilos-table-fallback"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div
        v-if="body === 'rows'"
        class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3"
        role="list"
        :aria-labelledby="nameId"
      >
        <div
          v-for="record in rows"
          :key="record.occurrence"
          class="col"
          role="listitem"
        >
          <HilosTableRecordCard
            :controller="controller"
            :view="record"
            :card="tileCard!"
            :image-column="imageColumn"
            :columns="frameColumns"
            :detail-fields="detailFields"
            :detail-id="`${tileDetailBaseId}-${record.rowKey}`"
            projection="tiles"
            :geometry="geometry"
            :reason-floor="reasonFloors.tiles"
            :selection-enabled="selectionEnabled"
            :selection-edge="selectionEdge"
            :progress="rowBar(record.rowKey)"
          >
            <template v-for="(_, name) in $slots" #[name]="slotProps">
              <slot :name="name" v-bind="slotProps" />
            </template>
          </HilosTableRecordCard>
        </div>
      </div>
      <div
        v-else-if="body === 'loading'"
        aria-busy="true"
        data-id="hilos-table-loading"
      >
        <span class="visually-hidden" role="status">Loading…</span>
        <div
          class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3"
          aria-hidden="true"
        >
          <div
            v-for="index in skeletonRows"
            :key="index"
            class="col"
            data-id="hilos-table-skeleton-row"
          >
            <div class="card">
              <div
                class="ratio ratio-4x3 bg-body-tertiary rounded-top border-bottom"
              ></div>
              <div class="card-body py-2 px-3 placeholder-glow">
                <span class="placeholder col-12"></span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <HilosTableEmptyState
        v-else
        :controller="controller"
        :kind="
          (body === 'empty_filtered' && declaration.filteredEmpty !== 'page') ||
          body === 'empty_page' ||
          body === 'unavailable'
            ? body
            : 'empty'
        "
      >
        <slot name="empty">No rows.</slot>
      </HilosTableEmptyState>
    </div>

    <HilosTableFooter :key="controllerMount" :controller="controller" />
  </div>
</template>
