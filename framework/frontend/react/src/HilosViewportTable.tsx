// HilosViewportTable — the thin React view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row
// renders as a placeholder in its slot — the layout never collapses. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from the page — one renderer per declared
// column (`cells`) for a table whose page declared a frame, the whole row (`row`)
// for one whose page did not — plus one framework-owned cell at the end of the row
// carrying what waits on that row; the placeholder, header, paging, and the one room
// of live messages above the rows stay framework-owned.
// (Distinct from HilosTable, the client-side view.) A table whose page DECLARED a
// frame draws the bar and the footer from that declaration instead
// (HilosTableBar, HilosTableFooter), and takes its columns, its name, and the words
// it says when empty from there too; a table whose page declared nothing is drawn
// from its props, the older of the two epochs, which the framework's log pages
// still take. A table whose page declared bulk operations also carries the
// framework's checkbox column, on whichever edge the installation provided — one
// choice for the whole application rather than a prop of this table
// (mockups/components/table section 6). Below the md breakpoint a declared table is
// a list of cards instead, built from the same declared columns and filled by the
// same cell renderers (mockups/components/table section 9). Bootstrap classes only.
import { Fragment, useContext, useId } from 'react'
import type { ReactNode } from 'react'
import {
  hilosTableOrderPosition,
  hilosTableSortPositionLabel,
} from '@hilos/core'
import type {
  HilosTableCard,
  HilosTableColumn,
  // Aliased because the component drawing one of these carries the same name.
  HilosTableProgress as TableProgressBar,
  TableSort,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { HilosTableBar } from './HilosTableBar.js'
import { HilosTableFooter } from './HilosTableFooter.js'
import { HilosTableLive } from './HilosTableLive.js'
import { HilosTableProgress } from './HilosTableProgress.js'
import { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'
import { HilosTableSelectionEdgeContext } from './hilosTableSelectionEdge.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosViewportTable}. */
export interface HilosViewportTableProps<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  controller: TableViewportController<R>
  /**
   * Column declarations for the header (labels and sort controls) of a table whose
   * page declared no frame; a declared table takes its columns from the declaration
   * and is not handed these.
   */
  columns?: HilosTableColumn[]
  /**
   * The content of one cell of a declared table, by the key of its column — the
   * content only, the framework writes the `<td>` around it and reads its class off
   * the column. A column this has no renderer for still gets its cell in the row.
   */
  cells?: Partial<Record<string, (row: R, rowKey: string) => ReactNode>>
  /**
   * Render the cells of one row of a table whose page declared no frame; the
   * returned `<td>`s fill the row. A declared table takes `cells` instead and is not
   * handed this.
   */
  row?: (row: R, rowKey: string) => ReactNode
  /** Accessible name for the table, rendered as a visually-hidden caption. */
  label?: string
  /** Show the search box above the table. */
  searchable?: boolean
  /** Placeholder for the search box. */
  searchPlaceholder?: string
  /** Message shown when there are no rows and the page declared no empty state. */
  emptyText?: string
  /** Message shown while the first window is still loading. */
  loadingText?: string
  /** Replace the empty-state cell content. */
  empty?: ReactNode
  /** Label shown in a removed row's placeholder slot. */
  placeholderText?: string
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
 * @param props The controller, columns, cell or row renderers, label / search / empty / placeholder config, and the project's places beside running work.
 */
export function HilosViewportTable<R>({
  controller,
  columns = [],
  cells,
  row,
  label,
  searchable = false,
  searchPlaceholder = 'Search…',
  emptyText = 'No rows.',
  loadingText = 'Loading…',
  empty,
  placeholderText = 'Removed',
  dataId = 'hilos-viewport-table',
  tableProgress,
  tableProgressAction,
  rowProgress,
  bulkUntouched,
}: HilosViewportTableProps<R>) {
  const rows = useSignal(controller.rows)
  const search = useSignal(controller.search)
  const order = useSignal(controller.order)
  const page = useSignal(controller.page)
  const pageCount = useSignal(controller.pageCount)
  const totalCount = useSignal(controller.totalCount)
  const totalExact = useSignal(controller.totalExact)
  const hasNextPage = useSignal(controller.hasNextPage)
  const pendingCount = useSignal(controller.pendingCount)
  const loaded = useSignal(controller.loaded)
  // The row bars this view draws itself. The table bar is drawn by the room of live
  // messages above the rows (HilosTableLive), and the bulk bar lives inside the
  // selection panel and is drawn by the bar above the table — anywhere else it would
  // take the room the table bar gives to the project.
  const rowProgressBars = useSignal(controller.progress.rows)
  // The marks, and the one sign that this table has them: a page that declared bulk
  // operations. There is no second sign — a table drawing its frame from props has
  // no declaration, so `enabled` is already false for it (Flow F14).
  const selection = controller.selection
  const selectionEnabled = selection.enabled
  const selectionHeader = useSignal(selection.header)
  // The declaration does not change over the life of a table, so it is read once
  // rather than wrapped in a signal (tableFrame.ts, HilosTableFrameState).
  const declaration = controller.frame.declaration
  // The columns the table is drawn from: the declaration's own where there is one,
  // and the prop for a table whose page declared nothing. The header and every cell
  // spanning the whole row count this one list, so they cannot disagree on its width.
  const frameColumns: readonly HilosTableColumn[] =
    declaration?.columns ?? columns
  // Which declared column takes which place of the card a row is drawn as on a
  // narrow screen — the head, the badge beside it, the labelled lines, the
  // controls. The core derived it from the declaration (tableCard.ts) and the view
  // has no arithmetic of its own about it; like the declaration it follows from, it
  // is a constant over the life of a table and null exactly when that is.
  const card = controller.frame.card
  // The row-state cell stands while anything waits. Header cell and body cell read
  // this ONE condition, so the two cannot drift apart into a row wider than its header.
  const markColumn = pendingCount > 0
  // Every cell that spans the whole row — the placeholder of a removed row, the empty
  // and loading states — counts the columns plus the mark column while it stands plus
  // the checkbox column while the table has marks. This is the ONE place the width is
  // worked out, and everything that spans a row reads it rather than counting again.
  const bodyColspan =
    frameColumns.length + (markColumn ? 1 : 0) + (selectionEnabled ? 1 : 0)
  const titleId = useId()
  // Which edge the checkbox column sits on — one choice for the whole installation
  // and not a prop of this table, because two tables of one product disagreeing
  // about it is the very thing the rule forbids (Design D5). A project that
  // provided nothing gets the left edge, where lists usually keep it.
  const selectionEdge = useContext(HilosTableSelectionEdgeContext)
  // What names a declared table: its own title when it declared one, and otherwise
  // the heading of the page it stands on, which already names it. Undefined for a
  // table that declared neither and stands outside an admin page — it has no name to
  // take.
  const pageHeadingId = useContext(HilosPageHeadingIdContext)
  const nameId = declaration?.title ? titleId : pageHeadingId
  // What a declared table says when it has no rows is the headline its page declared.
  // Only the headline: the hint under it, the main action beside it, and the
  // framework's own "Nothing found" under a search belong to an empty state this view
  // does not draw yet.
  const emptyWords = declaration?.empty?.title ?? emptyText
  // What stands where the rows would while there are none: a spinner until the first
  // window arrives, and the empty words after it. Drawn by both branches, so a narrow
  // screen says the same as a wide one.
  const stateWords = !loaded ? (
    <span
      className="d-inline-flex align-items-center gap-2"
      role="status"
      data-id="hilos-table-loading"
    >
      <span className="spinner-border spinner-border-sm" aria-hidden="true" />
      {loadingText}
    </span>
  ) : (
    (empty ?? emptyWords)
  )
  // A table whose count stopped at its ceiling has no page count to compare against, and
  // the footer is what such a table still needs: it is the only place saying there is more.
  const paginated = pageCount === null || pageCount > 1
  // The total reads as "at least this many" when the count stopped at its ceiling, which is
  // what the trailing plus says.
  const countLabel = totalExact ? `${totalCount} total` : `${totalCount}+ total`

  // The cells of a row bar's row, in the order the columns are declared: runs of
  // marked columns merge into one covered cell, runs of unmarked ones into one
  // empty cell, and the waiting cell is added exactly while it stands over the
  // ordinary rows. No column marked means one covered cell across the whole row.
  // The checkbox column takes an empty cell of its own on whichever edge it sits.
  //
  // The spans add up to bodyColspan by construction, which is what keeps the row
  // from growing wider than its header the moment a waiting change appears.
  const progressCells: ProgressCell[] = []
  for (const column of frameColumns) {
    const covered = column.progress === true
    const last = progressCells[progressCells.length - 1]
    if (last !== undefined && last.covered === covered) {
      last.span += 1
    } else {
      progressCells.push({ span: 1, covered })
    }
  }
  if (!frameColumns.some((column) => column.progress === true)) {
    progressCells.splice(0, progressCells.length, {
      span: frameColumns.length,
      covered: true,
    })
  }
  if (markColumn) {
    progressCells.push({ span: 1, covered: false })
  }
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

  // The cells of one row of a declared table: one per declared column, the content
  // from the page's renderer for that column and the class from the column itself.
  // The cell stands even where the page gave no renderer: a row one cell short is a
  // row narrower than its header (Flow F3).
  function declaredCells(record: R, rowKey: string): ReactNode {
    return frameColumns.map((column) => (
      <td key={column.key} className={column.cellClass}>
        {cells?.[column.key]?.(record, rowKey)}
      </td>
    ))
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
  // on the record, green for the couple of seconds after a value landed, and
  // nothing otherwise. It is worn as a border rather than a fill — Bootstrap's
  // contextual row classes are built for the cells of a table (Flow F5).
  function cardClass(view: TableViewportRow<R>): string | undefined {
    if (view.pending !== null) {
      return 'border-warning'
    }

    return view.highlighted ? 'border-success' : undefined
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
    const bar = rowBar(rowKey)

    return (
      <div className="card-body py-2 px-3">
        <div className="d-flex align-items-start gap-2 mb-1">
          {selectionEdge === 'start' ? selectBox : null}
          {hasCell(layout.title) ? (
            <span className="fw-medium">
              {cells?.[layout.title.key]?.(record, rowKey)}
            </span>
          ) : null}
          {/* The right of the head, in one group: what the PAGE says about the
              row first, then what the FRAMEWORK says about it. The page's badge
              is not pushed out by a framework mark — on a wide screen the two
              stand in two different cells, and a card is a second projection of
              the same columns rather than a smaller set of facts (Flow F6). */}
          <span className="ms-auto d-flex align-items-center gap-1">
            {hasCell(layout.badge)
              ? cells?.[layout.badge.key]?.(record, rowKey)
              : null}
            {view.pending === 'move' ? (
              <span
                className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                data-id={`hilos-table-pending-move-${rowKey}`}
              >
                <i className="bi bi-arrows-move" aria-hidden="true" /> Will move
              </span>
            ) : null}
            {view.pending === 'remove' ? (
              <span
                className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                data-id={`hilos-table-pending-remove-${rowKey}`}
              >
                <i className="bi bi-box-arrow-right" aria-hidden="true" /> Will
                leave
              </span>
            ) : null}
            {selectionEdge === 'end' ? selectBox : null}
          </span>
        </div>

        {/* What a column becomes on a narrow screen is a pair of a label and a
            value, and a description list is the one markup that says so to a
            screen reader (Flow F10). */}
        {fields.length > 0 ? (
          <dl className="row mb-2 small g-0">
            {fields.map((field) => (
              <Fragment key={field.key}>
                <dt className="col-5 fw-normal text-body-secondary">
                  {field.label}
                </dt>
                <dd className="col-7 mb-0">
                  {cells?.[field.key]?.(record, rowKey)}
                </dd>
              </Fragment>
            ))}
          </dl>
        ) : null}

        {/* The controls of the row, full width at the foot of the card. Which of
            them comes first is the markup the page hands over, and the framework
            neither reorders them nor takes one away (Flow F2). */}
        {hasCell(layout.actions) ? (
          <div className="d-grid gap-2">
            {cells?.[layout.actions.key]?.(record, rowKey)}
          </div>
        ) : null}

        {/* Work running over this one record, at the very bottom of the card and
            across its whole width: which columns a bar stretches under says
            nothing here, a card having no columns standing in a row (Flow F7). */}
        {bar !== undefined ? (
          <div className="mt-2" data-id={`hilos-table-progress-row-${rowKey}`}>
            {rowProgress !== undefined ? (
              <div className="small text-body-secondary mb-1">
                {rowProgress(bar, rowKey)}
              </div>
            ) : null}
            <HilosTableProgress progress={bar} label="Work on this row" />
          </div>
        ) : null}
      </div>
    )
  }

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
    <div data-id={dataId}>
      {declaration ? (
        <HilosTableBar
          controller={controller}
          titleId={titleId}
          bulkUntouched={bulkUntouched}
        />
      ) : null}

      {/* The bar a table draws from props, kept while a page still passes them —
          the framework's log pages do. It goes with the props themselves. */}
      {!declaration && searchable ? (
        <div className="mb-3">
          <input
            type="search"
            className="form-control"
            placeholder={searchPlaceholder}
            aria-label={searchPlaceholder}
            value={search}
            data-id="hilos-table-search"
            onChange={(event) => controller.setSearch(event.target.value)}
          />
        </div>
      ) : null}

      {/* Everything live the table has to say — work running over the set, a source
          gone quiet, rows created above the window, changes waiting for Apply — in
          one room that never changes height, outside both epochs of the frame: it
          speaks about what is happening to the rows, not about what the page
          declared. */}
      <HilosTableLive
        controller={controller}
        columns={frameColumns}
        tableProgress={tableProgress}
        tableProgressAction={tableProgressAction}
      />

      {/* A DECLARED table is a table on a wide screen and a list of cards on a
          narrow one, so its scroll wrapper goes with the table itself: there is
          nothing left to scroll sideways once the columns became lines of a card.
          A table still drawn from props has no cards to fall back on and keeps
          the wrapper at every width, scrollbar and all. */}
      <div
        className={
          declaration
            ? 'table-responsive d-none d-md-block'
            : 'table-responsive'
        }
      >
        <table
          className="table table-striped table-hover align-middle mb-0"
          aria-labelledby={declaration ? nameId : undefined}
        >
          {/* A declared table already shows its name as a heading, and a hidden
              caption repeating it would name the table twice. */}
          {!declaration && label ? (
            <caption className="visually-hidden">{label}</caption>
          ) : null}
          <thead>
            <tr>
              {/* The checkbox column stands on the edge the installation chose,
                  outside the declared columns on either side of them: it belongs to
                  the framework, and the page's columns are the page's. */}
              {selectionEdge === 'start' ? selectPageCell : null}
              {frameColumns.map((column) => (
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
                    </button>
                  ) : (
                    column.label
                  )}
                </th>
              ))}
              {markColumn ? (
                <th scope="col" className="text-end">
                  <span className="visually-hidden">
                    Row state and controls
                  </span>
                </th>
              ) : null}
              {selectionEdge === 'end' ? selectPageCell : null}
            </tr>
          </thead>
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
                        controller.selectRow(view.rowKey, event.target.checked)
                      }
                    />
                  </td>
                ) : null
              const bar = rowBar(view.rowKey)

              return (
                <Fragment key={view.rowKey}>
                  <tr
                    data-id={`hilos-table-row-${view.rowKey}`}
                    className={rowClass(view)}
                  >
                    {selectionEdge === 'start' ? selectRowCell : null}
                    {/* A placeholder row carries a null row; the null check also
                      narrows the type for the renderers. What stands where a row's
                      values do takes one shape per epoch of the frame. A DECLARED
                      table hands the page one renderer per column and writes the
                      cell around it: that is what makes a cell addressable by
                      column at all. A table still drawing its frame from props has
                      no column to address a cell by, so it keeps handing over the
                      whole row. */}
                    {view.placeholder || view.row === null ? (
                      <td
                        colSpan={bodyColspan}
                        className="text-center text-muted fst-italic"
                        data-id="hilos-table-placeholder"
                      >
                        {placeholderText}
                      </td>
                    ) : declaration ? (
                      declaredCells(view.row, view.rowKey)
                    ) : (
                      row?.(view.row, view.rowKey)
                    )}
                    {markColumn && !view.placeholder && view.row !== null ? (
                      <td className="text-end text-nowrap">
                        {view.pending === 'move' ? (
                          <span
                            className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                            data-id={`hilos-table-pending-move-${view.rowKey}`}
                          >
                            <i
                              className="bi bi-arrows-move"
                              aria-hidden="true"
                            />{' '}
                            Will move
                          </span>
                        ) : null}
                        {view.pending === 'remove' ? (
                          <span
                            className="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                            data-id={`hilos-table-pending-remove-${view.rowKey}`}
                          >
                            <i
                              className="bi bi-box-arrow-right"
                              aria-hidden="true"
                            />{' '}
                            Will leave
                          </span>
                        ) : null}
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
                    <tr data-id={`hilos-table-progress-row-${view.rowKey}`}>
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
                  ) : null}
                </Fragment>
              )
            })}
            {rows.length === 0 ? (
              <tr>
                <td
                  colSpan={bodyColspan}
                  className="text-center text-muted py-4"
                >
                  {stateWords}
                </td>
              </tr>
            ) : null}
          </tbody>
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
      {card !== null ? (
        <div className="d-md-none" data-id="hilos-table-cards">
          {rows.length > 0 ? (
            <div role="list" aria-labelledby={nameId}>
              {rows.map((view) => {
                const tint = cardClass(view)

                return (
                  <div
                    key={view.rowKey}
                    className={
                      tint === undefined ? 'card mb-2' : `card mb-2 ${tint}`
                    }
                    role="listitem"
                    data-id={`hilos-table-card-${view.rowKey}`}
                  >
                    {/* A removed row keeps its place as a card of one line,
                        exactly as it keeps it as a row of one cell: the set
                        never closes up under the reader (Flow F4). */}
                    {view.placeholder || view.row === null ? (
                      <div
                        className="card-body py-2 px-3 text-center text-body-secondary fst-italic small"
                        data-id="hilos-table-placeholder"
                      >
                        {placeholderText}
                      </div>
                    ) : (
                      cardBody(card, view, view.row)
                    )}
                  </div>
                )
              })}
            </div>
          ) : (
            // The two states a table says in words live inside the table in the
            // wide branch, so a narrow screen would hide them along with it and
            // the phone would be left with a blank space (Flow F12).
            <div className="text-center text-muted py-4">{stateWords}</div>
          )}
        </div>
      ) : null}

      {declaration ? <HilosTableFooter controller={controller} /> : null}

      {/* The footer a table draws from its own comparisons, for the same tables
          as the bar above. */}
      {!declaration && paginated ? (
        <div className="d-flex justify-content-between align-items-center mt-3">
          <span className="text-muted small" data-id="hilos-table-count">
            {countLabel}
          </span>
          <div className="btn-group" role="group" aria-label="Pagination">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={page === 0}
              data-id="hilos-table-prev"
              onClick={() => controller.prevPage()}
            >
              Previous
            </button>
            {pageCount === null ? null : (
              <span
                className="btn btn-sm disabled"
                aria-label={`Page ${page + 1} of ${pageCount}`}
                data-id="hilos-table-page"
              >
                {page + 1} / {pageCount}
              </span>
            )}
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={!hasNextPage}
              data-id="hilos-table-next"
              onClick={() => controller.nextPage()}
            >
              Next
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
