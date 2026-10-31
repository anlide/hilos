// HilosViewportTable — the thin React view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row renders
// as a placeholder in its slot until a replacement window arrives. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from the page — one renderer per declared
// column (`cells`) — plus one framework-owned cell at the end of the row carrying
// what waits on that row; the placeholder, header, paging, and the one room of live
// messages above the rows stay framework-owned. The table draws the bar and the
// footer from the page's HilosTableFrame (HilosTableBar, HilosTableFooter), and
// takes its columns, its name, and the words it says when empty from there too.
// A table whose page declared bulk operations also carries the
// framework's checkbox column, on whichever edge the installation provided — one
// choice for the whole application rather than a prop of this table
// (mockups/components/table section 6). Below the md breakpoint a table is
// a list of cards instead, built from the same declared columns and filled by the
// same cell renderers (mockups/components/table section 9). A field that did not
// fit a column of its own is declared `detail` and waits in a panel under the row:
// the framework owns the room, the order and the labels, while the page draws every
// value through `details`, exactly as it draws a cell (mockups/components/table
// section 4). A card opens into its own panel, inside its own body and off an id
// base of its own. The body is drawn from the state the core decides
// (HilosTableBody): rows, a skeleton of rows while a window change is late, or one
// of the four worded states drawn by HilosTableEmptyState — the page's own "nothing
// here yet", the framework's "Nothing found", and "List unavailable" (mockups/components/table section
// 10). Bootstrap classes only.
import { Fragment, useContext, useId, useLayoutEffect, useRef } from 'react'
import type { CSSProperties, ReactNode } from 'react'
import {
  TABLE_DETAIL_COPY,
  TABLE_STALENESS_COPY,
  HILOS_TABLE_ACTIONS_KEY,
  hilosTableDetailFields,
  hilosTableOrderPosition,
  hilosTablePlaceholder,
  hilosTableRowMessageCells,
  hilosTableSortPositionLabel,
  hilosTableStaleColumns,
  hilosTableStaleSources,
} from '@hilos/core/base'
import type {
  HilosTableCard,
  HilosTableColumn,
  // Aliased because the component drawing one of these carries the same name.
  HilosTableProgress as TableProgressBar,
  TableSort,
  TableProjection,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core/base'

import { HilosTableBar } from './HilosTableBar.js'
import { HilosTableEmptyState } from './HilosTableEmptyState.js'
import { HilosTableFooter } from './HilosTableFooter.js'
import { HilosTableLive } from './HilosTableLive.js'
import { HilosTableProgress } from './HilosTableProgress.js'
import { HilosTableValue } from './HilosTableValue.js'
import { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'
import { HilosTableSelectionEdgeContext } from './hilosTableSelectionEdge.js'
import { useSignal } from './useSignal.js'
import { useTableViewportGeometry } from './useTableViewportGeometry.js'

/** Props for {@link HilosViewportTable}. */
export interface HilosViewportTableProps<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  controller: TableViewportController<R>
  /**
   * The content of one cell of a table, by the key of its column — the
   * content only, the framework writes the `<td>` around it and reads its class off
   * the column. A column this has no renderer for still gets its cell in the row.
   */
  cells?: Partial<Record<string, (row: R, rowKey: string) => ReactNode>>
  /**
   * The content of one field of the panel a row expands into, by the key of its
   * column — the content only, the framework writes the label and the room around
   * it. A field this has no renderer for shows a dash.
   */
  details?: Partial<Record<string, (row: R, rowKey: string) => ReactNode>>
  /** Whether the search box owns focus when this table opens inside a modal. */
  autofocusSearch?: boolean
  /**
   * The page's own words in the "nothing here yet" state, when the frame declared
   * no empty state.
   */
  empty?: ReactNode
  /**
   * The `data-id` the table's root carries, for a page that draws more than one of them:
   * the default is the shared handle, and a second table on the same page names itself so
   * the two can be told apart from outside.
   */
  dataId?: string
  /**
   * The project's own words about the work running on the table, drawn on the line
   * of the room of live messages above the rows.
   */
  tableProgress?: (progress: TableProgressBar) => ReactNode
  /** The project's own control for that work, standing where a button stands. */
  tableProgressAction?: (progress: TableProgressBar) => ReactNode
  /**
   * The project's own words about the work running over one row, drawn above its
   * track; where this gives nothing, the row of the bar carries the track alone.
   */
  rowProgress?: (progress: TableProgressBar, rowKey: string) => ReactNode
  /**
   * The human name of one row a bulk run left untouched, for the report of the run;
   * the row's key is printed where this gives nothing.
   */
  bulkUntouched?: (rowKey: string, reason: string) => ReactNode
}

/** One cell of a row bar's row: how many columns it spans, and whether the bar is under it. */
type ProgressCell = { span: number; covered: boolean }

/**
 * The framework-owned table chrome over a headless {@link TableViewportController}.
 *
 * @param props The controller, cell and detail renderers, the empty words, and the
 *   project's places beside running work.
 */
export function HilosViewportTable<R>({
  controller,
  cells,
  details,
  autofocusSearch = false,
  empty,
  dataId = 'hilos-viewport-table',
  tableProgress,
  tableProgressAction,
  rowProgress,
  bulkUntouched,
}: HilosViewportTableProps<R>) {
  const rows = useSignal(controller.rows)
  const order = useSignal(controller.order)
  // Which state the body is in — rows, the skeleton, or one of the four worded
  // states. The core decides it (tableFrame.ts, HilosTableBody) so that the three
  // view layers cannot decide it three ways, and both branches below read this one
  // answer.
  const controlsAvailable = useSignal(controller.windowControlsAvailable)
  const body = useSignal(controller.frame.body)
  const pageSize = useSignal(controller.pageSize)
  // As many skeleton rows as the window had rows, so the height of the table does
  // not jump while the next one is on its way; a window that had none — a reset out
  // of "Nothing found" — is waiting for a full one (Flow F2).
  const skeletonRows = rows.length > 0 ? rows.length : pageSize
  // The row bars this view draws itself. The table bar and the bulk bar are drawn by
  // the room of live messages above the rows (HilosTableLive).
  const rowProgressBars = useSignal(controller.progress.rows)
  // The marks, and the one sign that this table has them: a page that declared bulk
  // operations (Flow F14).
  const selection = controller.selection
  const selectionEnabled = selection.enabled
  const selectionHeader = useSignal(selection.header)
  // The declaration does not change over the life of a table, so it is read once
  // rather than wrapped in a signal (tableFrame.ts, HilosTableFrameState).
  const declaration = controller.frame.declaration
  // The columns the table is drawn from, resolved by the core and reactive. The
  // header and every cell spanning the whole row count this one list, so they cannot
  // disagree on its width.
  const frameColumns = useSignal(controller.frame.columns)
  // The fields that wait in a panel instead of taking a column of their own, and the
  // columns that are left standing in the row. Every place that measures or draws the
  // row itself — the header, the width of a full-row cell, the cells of a row bar —
  // counts the second list, while the panel is built from the first.
  const detailFields = hilosTableDetailFields(frameColumns)
  const rowColumns = frameColumns.filter((column) => column.detail !== true)
  // Which sources went quiet anywhere in the shown window, and which declared columns
  // are built from them — the columns whose headers and row cells carry the mark. The
  // sentence about them is the room of live messages' own (HilosTableLive).
  const staleSources = hilosTableStaleSources(rows)
  const staleColumnKeys = new Set(
    hilosTableStaleColumns(frameColumns, staleSources).map(
      (column) => column.key,
    ),
  )
  // Which declared column takes which place of the card a row is drawn as on a
  // narrow screen — the head, the badge beside it, the labelled lines, the
  // controls. The core derived it from the declaration (tableCard.ts) and the view
  // has no arithmetic of its own about it; like the declaration it follows from, it
  // changes with the resolved columns.
  const card = useSignal(controller.frame.card)
  // State always has its own track, including before the first mark arrives.
  // Every full-row cell counts this same permanent room and optional selection.
  const bodyColspan = rowColumns.length + 1 + (selectionEnabled ? 1 : 0)
  const titleId = useId()
  // The base every expanded row's panel takes its id from — minted the same way the
  // title above is, and for the same reason: the control that points at a panel and
  // the panel itself are drawn in two places of one tree.
  const detailBaseId = useId()
  // The base the panel inside a CARD takes its id from — a second one, minted for the
  // same table. An id is unique in a document and both branches stand in it at once,
  // so a card borrowing the row's id would put that id in twice and break the tie
  // between control and panel on both.
  const cardDetailBaseId = useId()
  // Which edge the checkbox column sits on — one choice for the whole installation
  // and not a prop of this table, because two tables of one product disagreeing
  // about it is the very thing the rule forbids (Design D5). A project that
  // provided nothing gets the left edge, where lists usually keep it.
  const selectionEdge = useContext(HilosTableSelectionEdgeContext)
  // What names a table: its own title when it declared one, and otherwise
  // the heading of the page it stands on, which already names it. Undefined for a
  // table that declared neither and stands outside an admin page — it has no name to
  // take.
  const pageHeadingId = useContext(HilosPageHeadingIdContext)
  const nameId = declaration.title ? titleId : pageHeadingId
  const root = useRef<HTMLDivElement>(null)
  const table = useRef<HTMLTableElement>(null)
  const columnKeys = [
    ...rowColumns.map((column) => `column:${column.key}`),
    'state',
  ]
  if (selectionEnabled) {
    if (selectionEdge === 'start') columnKeys.unshift('selection')
    else columnKeys.push('selection')
  }
  const geometry = useTableViewportGeometry(
    controller.geometry,
    root,
    table,
    columnKeys,
    (record) => controller.isTextRecordCurrent(record),
  )
  const previousColumns = useRef(frameColumns)
  useLayoutEffect(() => {
    if (previousColumns.current !== frameColumns) {
      previousColumns.current = frameColumns
      geometry.layoutChanged()
    }
  }, [frameColumns, geometry.layoutChanged])

  // The cells of a row bar's row, in the order the columns are declared: runs of
  // marked columns merge into one covered cell, runs of unmarked ones into one
  // empty cell, followed by the permanent state cell. No column marked means
  // one covered cell across the content columns.
  // The checkbox column takes an empty cell of its own on whichever edge it sits.
  //
  // The spans add up to bodyColspan by construction, which is what keeps the row
  // from growing wider than its header the moment a waiting change appears.
  const progressCells: ProgressCell[] = []
  for (const column of rowColumns) {
    const covered = column.progress === true
    const last = progressCells[progressCells.length - 1]
    if (last !== undefined && last.covered === covered) {
      last.span += 1
    } else {
      progressCells.push({ span: 1, covered })
    }
  }
  if (!rowColumns.some((column) => column.progress === true)) {
    progressCells.splice(0, progressCells.length, {
      span: rowColumns.length,
      covered: true,
    })
  }
  progressCells.push({ span: 1, covered: false })
  if (selectionEnabled) {
    const selectionCell: ProgressCell = { span: 1, covered: false }
    if (selectionEdge === 'start') {
      progressCells.unshift(selectionCell)
    } else {
      progressCells.push(selectionCell)
    }
  }

  // The arrow a header carries: every column the order runs by gets one, because
  // an order of two columns is sorted by both of them and a single arrow would
  // name one of the two as the whole answer.
  function sortComponent(key: string): TableSort | undefined {
    return order?.find((component) => component.field === key)
  }

  // The bar running over one row, or undefined when none is. Read out of the map
  // by key rather than off the row's own projection, which is the tie the channel
  // exists to cut (tableProgress.ts): the bar outlives the window the row sits in.
  function rowBar(rowKey: string): TableProgressBar | undefined {
    return rowProgressBars.get(rowKey)
  }

  // The id of one row's panel, which the control above it points at through
  // aria-controls. One base for the whole table and the row key after it: the keys
  // are unique within a window, and two tables on one page mint two bases.
  function detailId(rowKey: string): string {
    return `${detailBaseId}-${rowKey}`
  }

  /** The same for the panel inside the card of that row, off its own base. */
  function cardDetailId(rowKey: string): string {
    return `${cardDetailBaseId}-${rowKey}`
  }

  // The value of one field of a panel. A field the page declared but drew nothing
  // into shows the dash its cells show, rather than an empty line that would read as
  // "there is no value" — and "drew nothing" is a key missing from `details`, not a
  // renderer that returned nothing, exactly as a slot the page filled but left empty.
  function detailValue(
    field: HilosTableColumn,
    record: R,
    rowKey: string,
  ): ReactNode {
    const render = details?.[field.key]

    return render === undefined
      ? TABLE_DETAIL_COPY.empty
      : render(record, rowKey)
  }

  // The control that opens a row or a card, pointed at the panel it opens. It calls
  // the core with the value it wants rather than toggling: the view keeps no state
  // of its own about which rows are open.
  function expandControl(
    view: TableViewportRow<R>,
    panelId: string,
    className: string,
  ): ReactNode {
    return (
      <button
        type="button"
        className={className}
        data-id={`hilos-table-expand-${view.rowKey}`}
        aria-expanded={view.expanded}
        aria-controls={panelId}
        onClick={() => controller.expandRow(view.rowKey, !view.expanded)}
      >
        <i
          className={`bi ${view.expanded ? 'bi-chevron-up' : 'bi-chevron-down'}`}
          aria-hidden="true"
        />
        <span className="visually-hidden">
          {view.expanded ? TABLE_DETAIL_COPY.hide : TABLE_DETAIL_COPY.show}
        </span>
      </button>
    )
  }

  // Only framework copy and inert spans size the mark room; no record renderer
  // or interactive twin participates in measuring it.
  function markRoom(): ReactNode {
    return (
      <span className="hilos-table-marks invisible" aria-hidden="true" inert>
        <i className="bi bi-snow" />
        <span className="hilos-stack">
          <span className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">
            <i className="bi bi-arrows-move" /> Will move
          </span>
          <span className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">
            <i className="bi bi-box-arrow-right" /> Will leave
          </span>
        </span>
        {detailFields.length > 0 ? (
          <span className="btn btn-sm btn-outline-secondary">
            <i className="bi bi-chevron-down" />
          </span>
        ) : null}
      </span>
    )
  }

  function frameworkMarks(
    view: TableViewportRow<R>,
    panelId: string,
  ): ReactNode {
    return (
      <span className="hilos-stack">
        {markRoom()}
        <span className="hilos-table-marks">
          {view.staleSources.length > 0 ? (
            <>
              <i
                className="bi bi-snow"
                data-id={`hilos-table-stale-row-${view.rowKey}`}
                aria-hidden="true"
              />
              <span className="visually-hidden">
                {TABLE_STALENESS_COPY.rowMark}
              </span>
            </>
          ) : null}
          {view.pending === 'move' ? (
            <span
              className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
              data-id={`hilos-table-pending-move-${view.rowKey}`}
            >
              <i className="bi bi-arrows-move" aria-hidden="true" /> Will move
            </span>
          ) : view.pending === 'remove' ? (
            <span
              className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
              data-id={`hilos-table-pending-remove-${view.rowKey}`}
            >
              <i className="bi bi-box-arrow-right" aria-hidden="true" /> Will
              leave
            </span>
          ) : null}
          {detailFields.length > 0 && view.expandable
            ? expandControl(
                view,
                panelId,
                'btn btn-sm btn-outline-secondary ms-auto',
              )
            : null}
        </span>
      </span>
    )
  }

  function headerSnowflake(column: HilosTableColumn): ReactNode {
    return column.source !== undefined ? (
      <span className="hilos-stack">
        <i className="bi bi-snow invisible" aria-hidden="true" inert />
        {staleColumnKeys.has(column.key) ? (
          <i
            className="bi bi-snow"
            data-id={`hilos-table-stale-column-${column.key}`}
            aria-hidden="true"
          />
        ) : null}
        <span className="visually-hidden">
          {staleColumnKeys.has(column.key) ? staleColumnText(column) : ''}
        </span>
      </span>
    ) : null
  }

  function reasonTwins(): ReactNode {
    return (
      <div className="hilos-table-reason-twins hilos-stack text-center fst-italic">
        {(['deleted', 'left_set', 'moved_out'] as const).map((reason) => {
          const placeholder = hilosTablePlaceholder(reason)
          return (
            <span key={reason}>
              <i className={`bi ${placeholder.icon} me-1`} />
              {placeholder.text}
            </span>
          )
        })}
      </div>
    )
  }

  // Both projections read the same occurrence through the existing geometry mount.
  function cellValue(
    column: HilosTableColumn,
    view: TableViewportRow<R>,
    record: R,
    projection: TableProjection,
  ): ReactNode {
    return (
      <HilosTableValue
        controller={controller}
        readRecord={geometry.recordReader(
          view.rowKey,
          view.occurrence,
          projection,
        )}
        columnKey={column.key}
        label={column.label}
        heightContent={column.heightContent}
        projection={projection}
      >
        {cells?.[column.key]?.(record, view.rowKey)}
      </HilosTableValue>
    )
  }

  // One cell per column even without a renderer, so the row matches its header.
  // A merged tail message replaces the remaining cells from fromColumn onward.
  function declaredCells(view: TableViewportRow<R>, record: R): ReactNode {
    const rowCells = hilosTableRowMessageCells(rowColumns, view.rowMessage)

    return rowCells.map((cell) => {
      if (cell.text !== null) {
        return (
          <td
            key={cell.key}
            colSpan={cell.span}
            className="text-body-secondary fst-italic"
            data-id="hilos-table-row-message"
          >
            {cell.text}
          </td>
        )
      }

      const column = rowColumns.find((col) => col.key === cell.key)

      return (
        <td key={cell.key} className={column?.cellClass}>
          {cell.key === HILOS_TABLE_ACTIONS_KEY ? (
            <span className="d-inline-flex align-items-center gap-1">
              {cells?.[cell.key]?.(record, view.rowKey)}
            </span>
          ) : column ? (
            cellValue(column, view, record, 'wide')
          ) : null}
        </td>
      )
    })
  }

  // Whether the page draws anything into a column's cell. A card leaves out the
  // place of a column the page gave no renderer for: a label with nothing under it
  // reads as a value lost rather than as an empty field, and on a phone it spends a
  // line of the screen saying that (Flow F3). The row is the other way round — its
  // cell always stands, or the row comes out narrower than its header — and that is
  // the one place the two branches part company.
  function hasCell(
    column: HilosTableColumn | null,
  ): column is HilosTableColumn {
    return column !== null && cells?.[column.key] !== undefined
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

  // The body of the card of one live row, in the order the mockup stacks it: the
  // head, the labelled lines, the controls, and the bar of the work running over
  // the record (Flow F1).
  function cardBody(
    layout: HilosTableCard,
    view: TableViewportRow<R>,
    record: R,
  ): ReactNode {
    const rowKey = view.rowKey
    // The mark sits on the edge the installation chose, the same edge it sits on
    // in the row: one product disagreeing with itself between its table and its
    // card is the very thing that choice forbids.
    const selectBox = selectionEnabled ? (
      <input
        className="form-check-input mt-1"
        type="checkbox"
        aria-label="Select row"
        data-id={`hilos-table-select-${rowKey}`}
        checked={view.selected}
        onChange={(event) => controller.selectRow(rowKey, event.target.checked)}
      />
    ) : null
    const fields = layout.fields.filter(hasCell)
    const cardCells =
      view.rowMessage !== null &&
      view.rowMessage !== undefined &&
      fields.length > 0
        ? hilosTableRowMessageCells(fields, view.rowMessage)
        : null
    const bar = rowBar(rowKey)

    return (
      <div
        className="card-body py-2 px-3"
        style={
          {
            '--hilos-table-reason-px': geometry.reasonFloors.card,
          } as CSSProperties
        }
      >
        <div className="d-flex flex-wrap align-items-start gap-2 mb-1">
          {selectionEdge === 'start' ? selectBox : null}
          {hasCell(layout.title) ? (
            <div className="fw-medium">
              {cellValue(layout.title, view, record, 'card')}
            </div>
          ) : null}
          {hasCell(layout.badge) ? (
            <span className="ms-auto">
              {cells?.[layout.badge.key]?.(record, rowKey)}
            </span>
          ) : null}
          <span className="hilos-table-card-marks d-flex justify-content-end align-items-center gap-1">
            {frameworkMarks(view, cardDetailId(rowKey))}
            {selectionEdge === 'end' ? selectBox : null}
          </span>
        </div>

        {/* What a column becomes on a narrow screen is a pair of a label and a
            value, and a description list is the one markup that says so to a
            screen reader (Flow F10). A merged tail message replaces tail
            fields with a single phrase (mockups/admin/maintenance). */}
        {fields.length > 0 ? (
          <dl className="row mb-2 small g-0">
            {cardCells !== null
              ? cardCells.map((cell) => {
                  if (cell.text !== null) {
                    return (
                      <dd
                        key={cell.key}
                        className="col-12 mb-0 text-body-secondary fst-italic"
                        data-id="hilos-table-row-message"
                      >
                        {cell.text}
                      </dd>
                    )
                  }
                  const field = fields.find((f) => f.key === cell.key)
                  if (field === undefined) {
                    return null
                  }

                  return (
                    <Fragment key={cell.key}>
                      <dt className="col-5 fw-normal text-body-secondary">
                        {field.label}
                      </dt>
                      <dd className="col-7 mb-0">
                        {cellValue(field, view, record, 'card')}
                      </dd>
                    </Fragment>
                  )
                })
              : fields.map((field) => (
                  <Fragment key={field.key}>
                    <dt className="col-5 fw-normal text-body-secondary">
                      {field.label}
                    </dt>
                    <dd className="col-7 mb-0">
                      {cellValue(field, view, record, 'card')}
                    </dd>
                  </Fragment>
                ))}
          </dl>
        ) : null}

        {/* What the reader opened, going on with the very pairs of label and value
            the fields above are: in a row the panel comes last of all, under the
            bar of the row's own work, but in a card the controls and that bar are
            the bottom block and the panel belongs with the body. */}
        {view.expanded ? (
          <div
            id={cardDetailId(rowKey)}
            ref={(node) =>
              geometry.bind(rowKey, view.occurrence, 'card', 'detail', node)
            }
            className="mb-2"
            data-id={`hilos-table-row-detail-${rowKey}`}
          >
            <dl className="row mb-0 small g-0">
              {/* A wide field puts its label above its value: the value is a
                  list of its own and needs the card's whole width. */}
              {detailFields.map((field) => (
                <Fragment key={field.key}>
                  <dt
                    className={`${field.detailWide === true ? 'col-12' : 'col-5'} fw-normal text-body-secondary`}
                  >
                    {field.label}
                  </dt>
                  <dd
                    className={`${field.detailWide === true ? 'col-12' : 'col-7'} mb-0 text-break`}
                  >
                    {detailValue(field, record, rowKey)}
                  </dd>
                </Fragment>
              ))}
            </dl>
          </div>
        ) : null}

        {/* The controls of the row, one row at the foot of the card, sharing
            it equally. Which of them comes first is the markup the page hands
            over, and the framework neither reorders them nor takes one away
            (Flow F2). */}
        {hasCell(layout.actions) ? (
          <div className="d-flex gap-2 hilos-button-row">
            {cells?.[layout.actions.key]?.(record, rowKey)}
          </div>
        ) : null}

        {/* Work running over this one record, at the very bottom of the card and
            across its whole width: which columns a bar stretches under says
            nothing here, a card having no columns standing in a row (Flow F7). */}
        {bar !== undefined ? (
          <div
            ref={(node) =>
              geometry.bind(rowKey, view.occurrence, 'card', 'work', node)
            }
            className="mt-2"
            data-id={`hilos-table-progress-row-${rowKey}`}
          >
            {rowProgress !== undefined ? (
              <div className="small text-body-secondary mb-1">
                {rowProgress(bar, rowKey)}
              </div>
            ) : null}
            <HilosTableProgress progress={bar} label="Work on this row" />
          </div>
        ) : geometry.workPixels(rowKey, 'card') > 0 ? (
          <div
            ref={(node) =>
              geometry.bind(rowKey, view.occurrence, 'card', 'work', node)
            }
            className="hilos-table-work-space hilos-table-work-reserve"
            aria-hidden="true"
            inert
            style={
              {
                '--hilos-table-work-px': geometry.workPixels(rowKey, 'card'),
              } as CSSProperties
            }
          />
        ) : null}
      </div>
    )
  }

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
    return hilosTableOrderPosition(order, key)
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
  // (Flow F1). The header box is one input for both directions: it goes to "all"
  // out of none and out of the half-marked state alike, and clears the window only
  // from "all" — which is what the core does with the state the box is now in
  // (Flow F2).
  const selectPageCell = selectionEnabled ? (
    <th scope="col" className="hilos-table-selection-cell">
      <input
        className="form-check-input"
        type="checkbox"
        aria-label="Select all rows on this page"
        data-id="hilos-table-select-page"
        checked={selectionHeader === 'all'}
        // React has no attribute for the half-marked state: it is a property of the
        // node, set on the mounted input (Flow F4).
        ref={(node) => {
          if (node !== null) {
            node.indeterminate = selectionHeader === 'some'
          }
        }}
        onChange={(event) => controller.selectWindow(event.target.checked)}
      />
    </th>
  ) : null

  return (
    <div ref={root} tabIndex={-1} data-id={dataId}>
      <HilosTableBar
        controller={controller}
        titleId={titleId}
        autofocusSearch={autofocusSearch}
      />

      {/* Everything live the table has to say — work running over the set, a source
          gone quiet, new rows the window cannot show, changes waiting for Apply — in
          one room that never changes height: it speaks about what is happening to
          the rows, not about what the page declared. */}
      <HilosTableLive
        controller={controller}
        columns={frameColumns}
        tableProgress={tableProgress}
        tableProgressAction={tableProgressAction}
        bulkUntouched={bulkUntouched}
      />

      {/* The scroll wrapper belongs to the wide table: once the columns became
          lines of a card, there is nothing left to scroll sideways. */}
      <div className="table-responsive d-none d-md-block position-relative">
        <div
          className="table hilos-table-room hilos-table-room-wide invisible"
          aria-hidden="true"
          inert
          style={
            {
              '--hilos-table-width-px': geometry.tracks?.tableWidth,
            } as CSSProperties
          }
        >
          <div className="hilos-table-replacement-body">
            <div className="hilos-table-replacement-row">
              <div className="hilos-table-replacement-cell">
                {reasonTwins()}
              </div>
            </div>
          </div>
          <div className="hilos-table-fallback" />
        </div>
        <table
          ref={table}
          className={`table table-striped table-hover align-middle mb-0${geometry.tracks !== null ? ' hilos-table-fixed' : ''}`}
          style={
            {
              '--hilos-table-width-px': geometry.tracks?.tableWidth,
              '--hilos-table-reason-px': geometry.reasonFloors.wide,
            } as CSSProperties
          }
          aria-labelledby={nameId}
        >
          <colgroup>
            {columnKeys.map((key, index) => (
              <col
                key={key}
                className="hilos-table-track"
                style={
                  {
                    '--hilos-table-track-px': geometry.trackWidths[index],
                  } as CSSProperties
                }
              />
            ))}
          </colgroup>
          <thead>
            <tr>
              {/* The checkbox column stands on the edge the installation chose,
                  outside the declared columns on either side of them: it belongs to
                  the framework, and the page's columns are the page's. */}
              {selectionEdge === 'start' ? selectPageCell : null}
              {rowColumns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  className={column.headerClass}
                  aria-sort={ariaSort(column)}
                >
                  {column.sortable ? (
                    <button
                      type="button"
                      className="btn btn-link p-0 text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                      data-id={`hilos-table-sort-${column.key}`}
                      disabled={!controlsAvailable}
                      onClick={() => controller.setSort(column.key)}
                    >
                      {column.label}
                      <i
                        className={`bi ${sortIcon(column.key)}`}
                        aria-hidden="true"
                      />
                      {sortPosition(column.key) !== null ? (
                        <>
                          <sup aria-hidden="true">
                            {sortPosition(column.key)}
                          </sup>
                          <span className="visually-hidden">
                            {hilosTableSortPositionLabel(
                              sortPosition(column.key)!,
                              order!.length,
                            )}
                          </span>
                        </>
                      ) : null}
                      {headerSnowflake(column)}
                    </button>
                  ) : (
                    <span className="d-inline-flex align-items-center gap-1">
                      {column.label}
                      {sortComponent(column.key) !== undefined ? (
                        <i
                          className={`bi ${sortIcon(column.key)}`}
                          aria-hidden="true"
                        />
                      ) : null}
                      {headerSnowflake(column)}
                    </span>
                  )}
                </th>
              ))}
              <th scope="col" className="text-end text-nowrap">
                {markRoom()}
                <span className="visually-hidden">Row state and controls</span>
              </th>
              {selectionEdge === 'end' ? selectPageCell : null}
            </tr>
          </thead>
          {body === 'loading' ? (
            // The skeleton stands in a body of its own while a window is late: a
            // cell for every column standing in the row, the framework's included,
            // so the columns keep their widths instead of collapsing into one cell
            // and jolting the table sideways on every change (Flow F3). The bars
            // say nothing to a screen reader; the hidden line says it in words.
            <tbody aria-busy="true" data-id="hilos-table-loading">
              {Array.from({ length: skeletonRows }, (_unused, index) => (
                <tr key={index} data-id="hilos-table-skeleton-row">
                  {Array.from({ length: bodyColspan }, (_unusedCell, cell) => (
                    <td key={cell} className="placeholder-glow">
                      {index === 0 && cell === 0 ? (
                        <span className="visually-hidden" role="status">
                          Loading…
                        </span>
                      ) : null}
                      <span className="placeholder col-12" aria-hidden="true" />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          ) : (
            <tbody>
              {rows.map((view) => {
                // A row drawn as a placeholder carries no checkbox: there is nothing
                // to mark in the trace of a row that left, and the core would not take
                // its key anyway (Flow F1). Its own cell spans the whole row, so the
                // column is simply not there for it.
                const selectRowCell =
                  selectionEnabled && !view.placeholder && view.row !== null ? (
                    <td className="hilos-table-selection-cell">
                      <input
                        className="form-check-input"
                        type="checkbox"
                        aria-label="Select row"
                        data-id={`hilos-table-select-${view.rowKey}`}
                        checked={view.selected}
                        onChange={(event) =>
                          controller.selectRow(
                            view.rowKey,
                            event.target.checked,
                          )
                        }
                      />
                    </td>
                  ) : null
                const bar = rowBar(view.rowKey)
                const record = view.row
                const placeholder = hilosTablePlaceholder(view.removal)

                return (
                  <Fragment key={view.occurrence}>
                    <tr
                      ref={(node) =>
                        geometry.bind(
                          view.rowKey,
                          view.occurrence,
                          'wide',
                          'main',
                          node,
                        )
                      }
                      data-id={`hilos-table-row-${view.rowKey}`}
                      className={`${rowClass(view) ?? ''}${view.placeholder ? '' : ' hilos-table-main'}`}
                    >
                      {selectionEdge === 'start' ? selectRowCell : null}
                      {/* A placeholder row carries a null row; the null check also
                      narrows the type for the renderers. */}
                      {view.placeholder || view.row === null ? (
                        <td
                          colSpan={bodyColspan}
                          className="hilos-table-placeholder-cell text-center text-body-secondary fst-italic"
                          data-id="hilos-table-placeholder"
                        >
                          <div
                            className="hilos-table-record-space"
                            style={
                              {
                                '--hilos-table-record-px':
                                  geometry.recordPixels(
                                    view.rowKey,
                                    view.occurrence,
                                    'wide',
                                  ),
                              } as CSSProperties
                            }
                          >
                            <span>
                              <i
                                className={`bi ${placeholder.icon} me-1`}
                                aria-hidden="true"
                              />
                              {placeholder.text}
                            </span>
                          </div>
                        </td>
                      ) : (
                        declaredCells(view, view.row)
                      )}
                      {!view.placeholder && view.row !== null ? (
                        <td className="text-end text-nowrap">
                          {frameworkMarks(view, detailId(view.rowKey))}
                        </td>
                      ) : null}
                      {selectionEdge === 'end' ? selectRowCell : null}
                    </tr>

                    {/* Work running over this one row, drawn right under it and only
                    while the row is on screen: a key absent from the window takes
                    up nothing and comes back with its row. A row drawn as a
                    placeholder gets no bar under it even if the key is still in the
                    map — the core takes a removed row's bar down at once, so that is
                    a race rather than a normal state, and the condition here is the
                    same pair that draws the placeholder above. */}
                    {bar !== undefined &&
                    !view.placeholder &&
                    view.row !== null ? (
                      <tr
                        ref={(node) =>
                          geometry.bind(
                            view.rowKey,
                            view.occurrence,
                            'wide',
                            'work',
                            node,
                          )
                        }
                        data-id={`hilos-table-progress-row-${view.rowKey}`}
                      >
                        {progressCells.map((cell, index) => (
                          <td key={index} colSpan={cell.span} className="pt-0">
                            {cell.covered ? (
                              <>
                                {rowProgress !== undefined ? (
                                  <div className="small text-body-secondary mb-1">
                                    {rowProgress(bar, view.rowKey)}
                                  </div>
                                ) : null}
                                <HilosTableProgress
                                  progress={bar}
                                  label="Work on this row"
                                />
                              </>
                            ) : null}
                          </td>
                        ))}
                      </tr>
                    ) : !view.placeholder &&
                      record !== null &&
                      geometry.workPixels(view.rowKey, 'wide') > 0 ? (
                      <tr
                        ref={(node) =>
                          geometry.bind(
                            view.rowKey,
                            view.occurrence,
                            'wide',
                            'work',
                            node,
                          )
                        }
                        className="hilos-table-work-reserve"
                        aria-hidden="true"
                        inert
                      >
                        <td
                          colSpan={bodyColspan}
                          className="hilos-table-placeholder-cell"
                        >
                          <div
                            className="hilos-table-work-space"
                            style={
                              {
                                '--hilos-table-work-px': geometry.workPixels(
                                  view.rowKey,
                                  'wide',
                                ),
                              } as CSSProperties
                            }
                          />
                        </td>
                      </tr>
                    ) : null}

                    {/* The panel this row expands into, drawn after the row's own
                    bar: the bar is a continuation of the row it belongs to, and
                    what the reader opened themselves comes after what is happening
                    to the record on its own. A placeholder never says it is
                    expanded; the second half of the condition says the same thing
                    to the compiler, so the fields are handed a row rather than a
                    row-or-nothing. */}
                    {view.expanded && record !== null ? (
                      <tr
                        id={detailId(view.rowKey)}
                        ref={(node) =>
                          geometry.bind(
                            view.rowKey,
                            view.occurrence,
                            'wide',
                            'detail',
                            node,
                          )
                        }
                        className="table-active"
                        data-id={`hilos-table-row-detail-${view.rowKey}`}
                      >
                        <td colSpan={bodyColspan} className="pt-0">
                          <dl className="row row-cols-1 row-cols-md-3 g-2 mb-0 small">
                            {/* A wide field takes the whole row of the grid on
                              every screen: col-md-12 is declared after
                              row-cols-md-3 and wins over it, so no style of our
                              own is needed. */}
                            {detailFields.map((field) => (
                              <div
                                key={field.key}
                                className={
                                  field.detailWide === true
                                    ? 'col col-md-12'
                                    : 'col'
                                }
                              >
                                <dt className="text-body-secondary fw-normal">
                                  {field.label}
                                </dt>
                                <dd className="mb-0 text-break">
                                  {detailValue(field, record, view.rowKey)}
                                </dd>
                              </div>
                            ))}
                          </dl>
                        </td>
                      </tr>
                    ) : null}
                  </Fragment>
                )
              })}
              {body !== 'rows' ? (
                <tr>
                  <td colSpan={bodyColspan}>
                    <HilosTableEmptyState
                      controller={controller}
                      kind={
                        (body === 'empty_filtered' &&
                          declaration.filteredEmpty !== 'page') ||
                        body === 'empty_page' ||
                        body === 'unavailable'
                          ? body
                          : 'empty'
                      }
                    >
                      {empty ?? 'No rows.'}
                    </HilosTableEmptyState>
                  </td>
                </tr>
              ) : null}
            </tbody>
          )}
        </table>
      </div>

      {/* The same rows as cards, the shape a table takes on a narrow screen: the
          framework builds each card out of the very columns the page declared, so
          no project writes a second markup for its table (mockup section 9). Both
          branches stand in the document at once and Bootstrap's visibility
          utilities show exactly one of them — there is no width at which both are
          seen, and crossing the boundary re-renders nothing (Flow F11). The price
          is that a row's data-id is in the document twice, which is why a test on
          a narrow screen aims at a row THROUGH this container
          (table-subscription.md, the registry of selectors). The cards are a list
          and carry the accessible name of the table itself: the same name rather
          than a second one, and never both at once — the branch that is hidden
          leaves the accessibility tree with its display (Flow F9). The list holds
          the cards and nothing else; what the table says in words when it has no
          rows stands BESIDE it, a sentence not being an item of a list. */}
      <div className="d-md-none position-relative" data-id="hilos-table-cards">
        <div
          className="hilos-table-room hilos-table-room-card invisible card mb-2"
          aria-hidden="true"
          inert
        >
          <div className="card-body py-2 px-3 small">
            {reasonTwins()}
            <div className="hilos-table-fallback" />
          </div>
        </div>
        {body === 'rows' ? (
          <div role="list" aria-labelledby={nameId}>
            {rows.map((view) => {
              const tint = cardClass(view)
              const placeholder = hilosTablePlaceholder(view.removal)

              return (
                <div
                  key={view.occurrence}
                  ref={(node) =>
                    geometry.bind(
                      view.rowKey,
                      view.occurrence,
                      'card',
                      'main',
                      node,
                    )
                  }
                  className={
                    tint === undefined ? 'card mb-2' : `card mb-2 ${tint}`
                  }
                  role="listitem"
                  data-id={`hilos-table-card-${view.rowKey}`}
                >
                  {/* Only a reason and the numeric full record allocation survive. */}
                  {view.placeholder || view.row === null ? (
                    <div
                      className="card-body py-2 px-3 text-center text-body-secondary fst-italic small"
                      data-id="hilos-table-placeholder"
                    >
                      <div
                        className="hilos-table-record-space"
                        style={
                          {
                            '--hilos-table-record-px': geometry.recordPixels(
                              view.rowKey,
                              view.occurrence,
                              'card',
                            ),
                          } as CSSProperties
                        }
                      >
                        <span>
                          <i
                            className={`bi ${placeholder.icon} me-1`}
                            aria-hidden="true"
                          />
                          {placeholder.text}
                        </span>
                      </div>
                    </div>
                  ) : (
                    cardBody(card, view, view.row)
                  )}
                </div>
              )
            })}
          </div>
        ) : body === 'loading' ? (
          // The skeleton and the four states a table says in words live inside
          // the table in the wide branch, so a narrow screen would hide them
          // along with it and the phone would be left with a blank space where
          // they are (Flow F12). A card of the skeleton is one bar, as the
          // mockup draws it.
          <div aria-busy="true" data-id="hilos-table-loading">
            <span className="visually-hidden" role="status">
              Loading…
            </span>
            {Array.from({ length: skeletonRows }, (_unused, index) => (
              <div
                key={index}
                className="card mb-2"
                aria-hidden="true"
                data-id="hilos-table-skeleton-row"
              >
                <div className="card-body py-2 px-3 placeholder-glow">
                  <span className="placeholder col-12" />
                </div>
              </div>
            ))}
          </div>
        ) : (
          <HilosTableEmptyState
            controller={controller}
            kind={
              (body === 'empty_filtered' &&
                declaration.filteredEmpty !== 'page') ||
              body === 'empty_page' ||
              body === 'unavailable'
                ? body
                : 'empty'
            }
          >
            {empty ?? 'No rows.'}
          </HilosTableEmptyState>
        )}
      </div>

      <HilosTableFooter controller={controller} />
    </div>
  )
}
