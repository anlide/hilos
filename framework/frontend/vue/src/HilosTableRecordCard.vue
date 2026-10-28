<!-- Internal record renderer. The table owns the geometry registration and
hands this view its current occurrence, layout, and exact-record DOM adapter. -->
<script setup lang="ts" generic="R">
import { useSlots } from 'vue'
import {
  TABLE_DETAIL_COPY,
  TABLE_STALENESS_COPY,
  TABLE_VIEW_COPY,
  hilosTablePlaceholder,
  hilosTableRowMessageCells,
  type HilosTableCard,
  type HilosTableColumn,
  type HilosTableProgress as TableProgressBar,
  type HilosTableRowMessage,
  type TableProjection,
  type TableViewportController,
  type TableViewportRow,
} from '@hilos/core/base'
import HilosTableProgress from './HilosTableProgress.vue'
import HilosTableValue from './HilosTableValue.vue'
import type { HilosTableSelectionEdge } from './hilosTableSelectionEdge.js'
import type { useTableViewportGeometry } from './useTableViewportGeometry.js'

const props = defineProps<{
  controller: TableViewportController<R>
  view: TableViewportRow<R>
  card: HilosTableCard
  imageColumn?: HilosTableColumn | null
  columns: readonly HilosTableColumn[]
  detailFields: readonly HilosTableColumn[]
  detailId: string
  projection: TableProjection
  geometry: Pick<
    ReturnType<typeof useTableViewportGeometry>,
    'bind' | 'recordReader' | 'recordPixels' | 'workPixels'
  >
  reasonFloor: number
  selectionEnabled: boolean
  selectionEdge: HilosTableSelectionEdge
  progress?: TableProgressBar
}>()

const slots = useSlots()

// Check each render: a forwarded slot may appear or disappear without a new layout.
function hasCell(column: HilosTableColumn | null): boolean {
  return column !== null && slots[`cell-${column.key}`] !== undefined
}

// A card's tint, resolved in the order the row's is: amber while a change waits
// on the record, green for the couple of seconds after a value landed, red when
// the record stands refused, and nothing otherwise. It is worn as a border
// rather than a fill — Bootstrap's contextual row classes are built for the
// cells of a table — and an expanded card takes no grey: the row wears grey to
// tie itself to a panel drawn under it, while a card holds its panel inside its
// own body (Flow F5).
function cardClass(view: TableViewportRow<R>): string | undefined {
  if (view.pending !== null) {
    return 'border-warning'
  }
  if (view.highlighted) {
    return 'border-success'
  }

  return view.refused ? 'border-danger' : undefined
}

interface HilosTableCardField {
  readonly key: string
  readonly label: string
  readonly heightContent?: HilosTableColumn['heightContent']
  readonly column?: HilosTableColumn
  readonly text?: string | null
}

// The labelled lines of a card: the fields of the layout the page actually drew
// into, or the merged tail message when the row carries one. A function rather than
// a computed, because what it reads is the slots and those are settled per render —
// a computed would hold the answer from the render the page passed other slots in.
function cardFields(
  rowOrMessage?: TableViewportRow<R> | HilosTableRowMessage | null,
): readonly HilosTableCardField[] {
  const message =
    rowOrMessage &&
    typeof rowOrMessage === 'object' &&
    'rowMessage' in rowOrMessage
      ? rowOrMessage.rowMessage
      : (rowOrMessage as HilosTableRowMessage | null | undefined)

  const declaredFields = props.card.fields.filter(hasCell)
  if (!message) {
    return declaredFields.map((column) => ({
      key: column.key,
      label: column.label,
      heightContent: column.heightContent,
      column,
      text: null,
    }))
  }

  const cells = hilosTableRowMessageCells(props.columns, message)
  const messageCell = cells.find((cell) => cell.text !== null)
  if (!messageCell) {
    return declaredFields.map((column) => ({
      key: column.key,
      label: column.label,
      heightContent: column.heightContent,
      column,
      text: null,
    }))
  }

  const ordinaryKeys = new Set(
    cells.filter((cell) => cell.text === null).map((cell) => cell.key),
  )

  const result: HilosTableCardField[] = declaredFields
    .filter((column) => ordinaryKeys.has(column.key))
    .map((column) => ({
      key: column.key,
      label: column.label,
      heightContent: column.heightContent,
      column,
      text: null,
    }))

  result.push({
    key: messageCell.key,
    label: '',
    text: messageCell.text,
  })

  return result
}

function onSelectRow(rowKey: string, event: Event): void {
  props.controller.selectRow(rowKey, (event.target as HTMLInputElement).checked)
}
</script>

<template>
  <div
    :ref="
      (el) =>
        geometry.bind(view.rowKey, view.occurrence, projection, 'main', el)
    "
    class="card"
    :class="[
      cardClass(view),
      projection === 'tiles' ? 'hilos-table-tile' : 'mb-2',
    ]"
    :role="projection === 'tiles' ? undefined : 'listitem'"
    :data-id="
      projection === 'tiles'
        ? `hilos-table-tile-${view.rowKey}`
        : `hilos-table-card-${view.rowKey}`
    "
  >
    <!-- Only a reason and empty numeric space survive departure. -->
    <div
      v-if="view.placeholder || view.row === null"
      class="card-body py-2 px-3 text-center text-body-secondary fst-italic small"
      data-id="hilos-table-placeholder"
    >
      <div
        class="hilos-table-record-space"
        :style="{
          '--hilos-table-record-px': geometry.recordPixels(
            view.rowKey,
            view.occurrence,
            projection,
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
    </div>
    <template v-else>
      <div
        v-if="projection === 'tiles'"
        class="ratio ratio-4x3 bg-body-tertiary rounded-top border-bottom"
        :data-id="`hilos-table-tile-image-${view.rowKey}`"
      >
        <slot
          v-if="imageColumn"
          :name="`cell-${imageColumn.key}`"
          :row="view.row"
          :row-key="view.rowKey"
        >
          <div
            class="d-flex flex-column align-items-center justify-content-center text-body-secondary gap-1"
          >
            <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
            <span class="small">{{ TABLE_VIEW_COPY.noImage }}</span>
          </div>
        </slot>
      </div>
      <div
        class="card-body py-2 px-3"
        :style="{ '--hilos-table-reason-px': reasonFloor }"
      >
        <div class="d-flex flex-wrap align-items-start gap-2 mb-1">
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
          <div
            v-if="hasCell(card.title)"
            class="fw-medium flex-grow-1 min-w-0 text-break"
          >
            <HilosTableValue
              :key="`${card.title!.key}:${card.title!.heightContent}`"
              :controller="props.controller"
              :read-record="
                geometry.recordReader(view.rowKey, view.occurrence, projection)
              "
              :column-key="card.title!.key"
              :label="card.title!.label"
              :height-content="card.title!.heightContent"
              :projection="projection"
            >
              <slot
                :name="`cell-${card.title!.key}`"
                :row="view.row"
                :row-key="view.rowKey"
              />
            </HilosTableValue>
          </div>
          <!-- The right of the head, in one group: what the PAGE says about
        the row first, then what the FRAMEWORK says about it. The page's
        badge is not pushed out by a framework mark — on a wide screen the
        two stand in two different cells, and a card is a second
        projection of the same columns rather than a smaller set of facts
        (Design D9, design debt D-058). -->
          <span class="ms-auto">
            <slot
              v-if="hasCell(card.badge)"
              :name="`cell-${card.badge!.key}`"
              :row="view.row"
              :row-key="view.rowKey"
            />
          </span>
          <span class="hilos-table-card-marks d-flex justify-content-end">
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
                  <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                  Will leave
                </span>
                <!-- The control comes after everything that merely STATES
          something about the record, exactly as it does at the end of a
          row: it is the one thing in the head the reader acts on. -->
                <button
                  v-if="detailFields.length > 0 && view.expandable"
                  type="button"
                  class="btn btn-sm btn-outline-secondary ms-auto"
                  :data-id="`hilos-table-expand-${view.rowKey}`"
                  :aria-expanded="view.expanded"
                  :aria-controls="detailId"
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
      to a screen reader (Flow F10). A merged tail message replaces tail
      fields with a single phrase (mockups/admin/maintenance). -->
        <dl
          v-if="cardFields(view.rowMessage).length > 0"
          class="row mb-2 small g-0"
        >
          <template
            v-for="field in cardFields(view.rowMessage)"
            :key="field.key"
          >
            <div
              v-if="field.text !== null && field.text !== undefined"
              class="col-12 text-body-secondary fst-italic"
              data-id="hilos-table-row-message"
            >
              {{ field.text }}
            </div>
            <template v-else-if="field.column">
              <dt class="col-5 fw-normal text-body-secondary">
                {{ field.label }}
              </dt>
              <dd class="col-7 mb-0">
                <HilosTableValue
                  :key="field.heightContent"
                  :controller="props.controller"
                  :read-record="
                    geometry.recordReader(
                      view.rowKey,
                      view.occurrence,
                      projection,
                    )
                  "
                  :column-key="field.key"
                  :label="field.label"
                  :height-content="field.heightContent"
                  :projection="projection"
                >
                  <slot
                    :name="`cell-${field.key}`"
                    :row="view.row"
                    :row-key="view.rowKey"
                  />
                </HilosTableValue>
              </dd>
            </template>
          </template>
        </dl>

        <!-- What the reader opened, going on with the very pairs of label
      and value the fields above are: in a row the panel comes last of
      all, under the bar of the row's own work, but in a card the controls
      and that bar are the bottom block and the panel belongs with the
      body. -->
        <div
          v-if="view.expanded"
          :id="detailId"
          :ref="
            (el) =>
              geometry.bind(
                view.rowKey,
                view.occurrence,
                projection,
                'detail',
                el,
              )
          "
          class="mb-2"
          :data-id="`hilos-table-row-detail-${view.rowKey}`"
        >
          <dl class="row mb-0 small g-0">
            <template v-for="field in detailFields" :key="field.key">
              <!-- A wide field puts its label above its value: the value
            is a list of its own and needs the card's whole width. -->
              <dt
                :class="[
                  field.detailWide === true ? 'col-12' : 'col-5',
                  'fw-normal text-body-secondary',
                ]"
              >
                {{ field.label }}
              </dt>
              <dd
                :class="[
                  field.detailWide === true ? 'col-12' : 'col-7',
                  'mb-0 text-break',
                ]"
              >
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

        <!-- The controls of the row, one row at the foot of the card,
      sharing it equally. Which of them comes first is the markup the page
      hands over, and the framework neither reorders them nor takes one
      away (Flow F2). -->
        <div v-if="hasCell(card.actions)" class="d-flex gap-2 hilos-button-row">
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
          v-if="progress !== undefined"
          :ref="
            (el) =>
              geometry.bind(
                view.rowKey,
                view.occurrence,
                projection,
                'work',
                el,
              )
          "
          class="mt-2"
          :data-id="`hilos-table-progress-row-${view.rowKey}`"
        >
          <div
            v-if="$slots['row-progress']"
            class="small text-body-secondary mb-1"
          >
            <slot
              name="row-progress"
              :progress="progress!"
              :row-key="view.rowKey"
            />
          </div>
          <HilosTableProgress :progress="progress!" label="Work on this row" />
        </div>
        <div
          v-else-if="geometry.workPixels(view.rowKey, projection) > 0"
          :ref="
            (el) =>
              geometry.bind(
                view.rowKey,
                view.occurrence,
                projection,
                'work',
                el,
              )
          "
          class="hilos-table-work-space hilos-table-work-reserve"
          aria-hidden="true"
          inert
          :style="{
            '--hilos-table-work-px': geometry.workPixels(
              view.rowKey,
              projection,
            ),
          }"
        ></div>
      </div>
    </template>
  </div>
</template>
