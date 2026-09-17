// HilosViewportTable — the thin Angular view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row
// renders as a placeholder in its slot — the layout never collapses. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from an
// `<ng-template #row let-row let-rowKey="rowKey">`, plus one framework-owned cell at
// the end of the row carrying what waits on that row; the placeholder, header,
// paging, and the one room of live messages above the rows stay framework-owned.
// The controller arrives via
// input, carrying core signals, so the view mirrors them into Angular signals.
// (Distinct from HilosTable, the client-side view.) A table whose page DECLARED a
// frame draws the bar and the footer from that declaration instead
// (HilosTableBar, HilosTableFooter), and takes its columns, its name, and the words
// it says when empty from there too; a table whose page declared nothing is drawn
// from its inputs, the older of the two epochs, which the framework's log pages
// still take. A table whose page declared bulk operations also carries the
// framework's checkbox column, on whichever edge the installation provided — one
// choice for the whole application rather than an input of this table
// (mockups/components/table section 6). Bootstrap classes only.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import type { TemplateRef, WritableSignal } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type {
  HilosTableColumn,
  HilosTableProgress as HilosTableProgressState,
  HilosTableSelectionHeader,
  ReadonlySignal,
  TableSort,
  TableSortOrder,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { HilosTableBar } from './HilosTableBar.js'
import { HilosTableFooter } from './HilosTableFooter.js'
import { HilosTableLive } from './HilosTableLive.js'
import { HilosTableProgress } from './HilosTableProgress.js'
import { HILOS_PAGE_HEADING_ID } from './hilosPageHeadingToken.js'
import { HILOS_TABLE_SELECTION_EDGE } from './hilosTableSelectionEdge.js'

// Distinct ids so two declared tables on one page never name themselves by the
// same title.
let viewportTableSeq = 0

/** The context a HilosViewportTable `#row` template receives. */
export interface ViewportTableRowContext<R> {
  /** The resolved row view-model (the template's implicit `let-row`). */
  $implicit: R
  /** The row's stable key. */
  rowKey: string
}

/** The context a HilosViewportTable `#tableProgress` or `#tableProgressAction` template receives. */
export interface ViewportTableProgressContext {
  /** The bar of the work running on the table (the template's implicit `let-progress`). */
  $implicit: HilosTableProgressState
}

/** The context a HilosViewportTable `#rowProgress` template receives. */
export interface ViewportTableRowProgressContext {
  /** The bar of the work running over one row (the template's implicit `let-progress`). */
  $implicit: HilosTableProgressState
  /** The key of the row the work runs over. */
  rowKey: string
}

/** One cell of a row bar's row: how many columns it spans, and whether the bar is under it. */
type ProgressCell = { span: number; covered: boolean }

/** The context a HilosViewportTable `#bulkUntouched` template receives. */
export interface BulkUntouchedContext {
  /** The key of the row a bulk run left untouched (the template's implicit `let-rowKey`). */
  $implicit: string
  /** Why the run left it alone, as the server said it. */
  reason: string
}

/** The framework-owned table chrome over a headless TableViewportController. */
@Component({
  selector: 'hilos-viewport-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosTableBar,
    HilosTableFooter,
    HilosTableLive,
    HilosTableProgress,
    NgTemplateOutlet,
  ],
  template: `
    <div [attr.data-id]="dataId()">
      @if (declaration()) {
        <hilos-table-bar
          [controller]="controller()"
          [titleId]="titleId"
          [bulkUntouched]="bulkUntouched()"
        />
      }

      <!-- The bar a table draws from inputs, kept while a page still passes them —
      the framework's log pages do. It goes with the inputs themselves. -->
      @if (!declaration() && searchable()) {
        <div class="mb-3">
          <input
            type="search"
            class="form-control"
            [placeholder]="searchPlaceholder()"
            [attr.aria-label]="searchPlaceholder()"
            [value]="search()"
            data-id="hilos-table-search"
            (input)="onSearchInput($event)"
          />
        </div>
      }

      <!-- Everything live the table has to say — work running over the set, a
      source gone quiet, rows created above the window, changes waiting for Apply —
      in one room that never changes height, outside both epochs of the frame: it
      speaks about what is happening to the rows, not about what the page
      declared. -->
      <hilos-table-live
        [controller]="controller()"
        [columns]="frameColumns()"
        [tableProgress]="tableProgress()"
        [tableProgressAction]="tableProgressAction()"
      />

      <div class="table-responsive">
        <table
          class="table table-striped table-hover align-middle mb-0"
          [attr.aria-labelledby]="declaration() ? nameId() : null"
        >
          <!-- A declared table already shows its name as a heading, and a hidden
          caption repeating it would name the table twice. -->
          @if (!declaration() && label(); as label) {
            <caption class="visually-hidden">
              {{
                label
              }}
            </caption>
          }
          <thead>
            <tr>
              <!-- The checkbox column stands on the edge the installation chose,
              outside the declared columns on either side of them: it belongs to
              the framework, and the page's columns are the page's. -->
              @if (selectionEnabled() && selectionEdge === 'start') {
                <th scope="col" class="hilos-table-selection-cell">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    aria-label="Select all rows on this page"
                    data-id="hilos-table-select-page"
                    [checked]="selectionHeader() === 'all'"
                    [indeterminate]="selectionHeader() === 'some'"
                    (change)="onSelectPage($event)"
                  />
                </th>
              }
              @for (column of frameColumns(); track column.key) {
                <th
                  scope="col"
                  [class]="column.headerClass ?? ''"
                  [attr.aria-sort]="ariaSort(column)"
                >
                  @if (column.sortable) {
                    <button
                      type="button"
                      class="btn btn-link p-0 text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                      [attr.data-id]="'hilos-table-sort-' + column.key"
                      (click)="controller().setSort(column.key)"
                    >
                      {{ column.label }}
                      <i
                        [class]="'bi ' + sortIcon(column.key)"
                        aria-hidden="true"
                      ></i>
                    </button>
                  } @else {
                    {{ column.label }}
                  }
                </th>
              }
              @if (markColumn()) {
                <th scope="col" class="text-end">
                  <span class="visually-hidden">Row state and controls</span>
                </th>
              }
              @if (selectionEnabled() && selectionEdge === 'end') {
                <th scope="col" class="hilos-table-selection-cell">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    aria-label="Select all rows on this page"
                    data-id="hilos-table-select-page"
                    [checked]="selectionHeader() === 'all'"
                    [indeterminate]="selectionHeader() === 'some'"
                    (change)="onSelectPage($event)"
                  />
                </th>
              }
            </tr>
          </thead>
          <tbody>
            @for (view of rows(); track view.rowKey) {
              <tr
                [attr.data-id]="'hilos-table-row-' + view.rowKey"
                [class]="rowClass(view)"
              >
                <!-- A row drawn as a placeholder carries no checkbox: there is
                nothing to mark in the trace of a row that left, and the core would
                not take its key anyway (Flow F1). Its own cell spans the whole row,
                so the column is simply not there for it. -->
                @if (
                  selectionEnabled() &&
                  selectionEdge === 'start' &&
                  !view.placeholder
                ) {
                  <td class="hilos-table-selection-cell">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      aria-label="Select row"
                      [attr.data-id]="'hilos-table-select-' + view.rowKey"
                      [checked]="view.selected"
                      (change)="onSelectRow(view.rowKey, $event)"
                    />
                  </td>
                }
                @if (view.placeholder) {
                  <td
                    [attr.colspan]="bodyColspan()"
                    class="text-center text-muted fst-italic"
                    data-id="hilos-table-placeholder"
                  >
                    {{ placeholderText() }}
                  </td>
                } @else {
                  <ng-container
                    [ngTemplateOutlet]="row()"
                    [ngTemplateOutletContext]="{
                      $implicit: view.row,
                      rowKey: view.rowKey,
                    }"
                  />
                }
                @if (markColumn() && !view.placeholder) {
                  <td class="text-end text-nowrap">
                    @if (view.pending === 'move') {
                      <span
                        class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                        [attr.data-id]="
                          'hilos-table-pending-move-' + view.rowKey
                        "
                      >
                        <i class="bi bi-arrows-move" aria-hidden="true"></i>
                        Will move
                      </span>
                    } @else if (view.pending === 'remove') {
                      <span
                        class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                        [attr.data-id]="
                          'hilos-table-pending-remove-' + view.rowKey
                        "
                      >
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        Will leave
                      </span>
                    }
                  </td>
                }
                @if (
                  selectionEnabled() &&
                  selectionEdge === 'end' &&
                  !view.placeholder
                ) {
                  <td class="hilos-table-selection-cell">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      aria-label="Select row"
                      [attr.data-id]="'hilos-table-select-' + view.rowKey"
                      [checked]="view.selected"
                      (change)="onSelectRow(view.rowKey, $event)"
                    />
                  </td>
                }
              </tr>

              <!-- Work running over this one row, drawn right under it and only
              while the row is on screen: a key absent from the window takes up
              nothing and comes back with its row. A row drawn as a placeholder
              gets no bar under it even if the key is still in the map — the core
              takes a removed row's bar down at once, so that is a race rather
              than a normal state, and the condition here is the same one that
              draws the placeholder above. -->
              @if (!view.placeholder && rowBar(view.rowKey); as bar) {
                <tr [attr.data-id]="'hilos-table-progress-row-' + view.rowKey">
                  @for (cell of progressCells(); track $index) {
                    <td [attr.colspan]="cell.span" class="pt-0">
                      @if (cell.covered) {
                        @if (rowProgress(); as caption) {
                          <div class="small text-body-secondary mb-1">
                            <ng-container
                              [ngTemplateOutlet]="caption"
                              [ngTemplateOutletContext]="{
                                $implicit: bar,
                                rowKey: view.rowKey,
                              }"
                            />
                          </div>
                        }
                        <hilos-table-progress
                          [progress]="bar"
                          label="Work on this row"
                        />
                      }
                    </td>
                  }
                </tr>
              }
            }
            @if (rows().length === 0) {
              <tr>
                <td
                  [attr.colspan]="bodyColspan()"
                  class="text-center text-muted py-4"
                >
                  @if (!loaded()) {
                    <span
                      class="d-inline-flex align-items-center gap-2"
                      role="status"
                      data-id="hilos-table-loading"
                    >
                      <span
                        class="spinner-border spinner-border-sm"
                        aria-hidden="true"
                      ></span>
                      {{ loadingText() }}
                    </span>
                  } @else if (empty(); as emptyTemplate) {
                    <ng-container [ngTemplateOutlet]="emptyTemplate" />
                  } @else {
                    {{ emptyWords() }}
                  }
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      @if (declaration()) {
        <hilos-table-footer [controller]="controller()" />
      }

      <!-- The footer a table draws from its own comparisons, for the same tables
      as the bar above. -->
      @if (!declaration() && paginated()) {
        <div class="d-flex justify-content-between align-items-center mt-3">
          <span class="text-muted small" data-id="hilos-table-count">
            {{ countLabel() }}
          </span>
          <div class="btn-group" role="group" aria-label="Pagination">
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="page() === 0"
              data-id="hilos-table-prev"
              (click)="controller().prevPage()"
            >
              Previous
            </button>
            @if (pageCount() !== null) {
              <span
                class="btn btn-sm disabled"
                [attr.aria-label]="
                  'Page ' + (page() + 1) + ' of ' + pageCount()
                "
                data-id="hilos-table-page"
              >
                {{ page() + 1 }} / {{ pageCount() }}
              </span>
            }
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="!hasNextPage()"
              data-id="hilos-table-next"
              (click)="controller().nextPage()"
            >
              Next
            </button>
          </div>
        </div>
      }
    </div>
  `,
})
export class HilosViewportTable<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  readonly controller = input.required<TableViewportController<R>>()
  /**
   * Column declarations for the header (labels and sort controls) of a table whose
   * page declared no frame; a declared table takes its columns from the declaration
   * and is not handed these.
   */
  readonly columns = input<HilosTableColumn[]>([])
  /** Accessible name for the table, rendered as a visually-hidden caption. */
  readonly label = input<string>()
  /** Show the search box above the table. */
  readonly searchable = input(false)
  /** Placeholder for the search box. */
  readonly searchPlaceholder = input('Search…')
  /** Message shown when there are no rows and the page declared no empty state. */
  readonly emptyText = input('No rows.')
  /** Message shown while the first window is still loading. */
  readonly loadingText = input('Loading…')
  /** Label shown in a removed row's placeholder slot. */
  readonly placeholderText = input('Removed')
  /**
   * The `data-id` the table's root carries, for a page that draws more than one of them:
   * the default is the shared handle, and a second table on the same page names itself so
   * the two can be told apart from outside.
   */
  readonly dataId = input('hilos-viewport-table')

  protected readonly row =
    contentChild.required<TemplateRef<ViewportTableRowContext<R>>>('row')
  protected readonly empty = contentChild<TemplateRef<unknown>>('empty')
  /**
   * The human name of one row a bulk run left untouched, for the report of the run:
   * `<ng-template #bulkUntouched let-rowKey let-reason="reason">`. The row's key is
   * printed where the page gives none.
   */
  protected readonly bulkUntouched =
    contentChild<TemplateRef<BulkUntouchedContext>>('bulkUntouched')
  /**
   * The project's own words about the work running on the table, drawn on the line
   * of the room of live messages above the rows: `<ng-template #tableProgress
   * let-progress>`.
   */
  protected readonly tableProgress =
    contentChild<TemplateRef<ViewportTableProgressContext>>('tableProgress')
  /**
   * The project's own control for that work, standing where a button stands:
   * `<ng-template #tableProgressAction let-progress>`.
   */
  protected readonly tableProgressAction = contentChild<
    TemplateRef<ViewportTableProgressContext>
  >('tableProgressAction')
  /**
   * The project's own words about the work running over one row, drawn above its
   * track: `<ng-template #rowProgress let-progress let-rowKey="rowKey">`. Where the
   * page gives none, the row of the bar carries the track alone.
   */
  protected readonly rowProgress =
    contentChild<TemplateRef<ViewportTableRowProgressContext>>('rowProgress')

  // The declaration does not change over the life of a table, so it is read off
  // the controller rather than mirrored as a signal (tableFrame.ts,
  // HilosTableFrameState).
  protected readonly declaration = computed(
    () => this.controller().frame.declaration,
  )
  // The columns the table is drawn from: the declaration's own where there is one,
  // and the input for a table whose page declared nothing. The header and every cell
  // spanning the whole row count this one list, so they cannot disagree on its width.
  protected readonly frameColumns = computed<readonly HilosTableColumn[]>(
    () => this.declaration()?.columns ?? this.columns(),
  )
  // The marks, and the one sign that this table has them: a page that declared bulk
  // operations. There is no second sign — a table drawing its frame from inputs has
  // no declaration, so `enabled` is already false for it (Flow F14).
  protected readonly selectionEnabled = computed(
    () => this.controller().selection.enabled,
  )
  protected readonly selectionHeader = signal<HilosTableSelectionHeader>('none')
  // Which edge the checkbox column sits on — one choice for the whole installation
  // and not an input of this table, because two tables of one product disagreeing
  // about it is the very thing the rule forbids (Design D5). A project that
  // provided nothing gets the left edge, where lists usually keep it.
  protected readonly selectionEdge =
    inject(HILOS_TABLE_SELECTION_EDGE, { optional: true }) ?? 'start'
  // The row-state cell stands while anything waits. Header cell and body cell read
  // this ONE condition, so the two cannot drift apart into a row wider than its header.
  protected readonly markColumn = computed(() => this.pendingCount() > 0)
  // Every cell that spans the whole row — the placeholder of a removed row, the empty
  // and loading states — counts the columns plus the mark column while it stands plus
  // the checkbox column while the table has marks. This is the ONE place the width is
  // worked out, and everything that spans a row reads it rather than counting again.
  protected readonly bodyColspan = computed(
    () =>
      this.frameColumns().length +
      (this.markColumn() ? 1 : 0) +
      (this.selectionEnabled() ? 1 : 0),
  )
  protected readonly titleId = `hilos-table-title-${viewportTableSeq++}`
  // What names a declared table: its own title when it declared one, and otherwise
  // the heading of the page it stands on, which already names it. Null for a table
  // that declared neither and stands outside an admin page — it has no name to take.
  private readonly pageHeadingId = inject(HILOS_PAGE_HEADING_ID, {
    optional: true,
  })
  protected readonly nameId = computed(() =>
    this.declaration()?.title ? this.titleId : this.pageHeadingId,
  )
  // What a declared table says when it has no rows is the headline its page declared.
  // Only the headline: the hint under it, the main action beside it, and the
  // framework's own "Nothing found" under a search belong to an empty state this view
  // does not draw yet.
  protected readonly emptyWords = computed(
    () => this.declaration()?.empty?.title ?? this.emptyText(),
  )

  protected readonly rows = signal<readonly TableViewportRow<R>[]>([])
  protected readonly search = signal('')
  protected readonly order = signal<TableSortOrder | undefined>(undefined)
  protected readonly page = signal(0)
  protected readonly pageCount = signal<number | null>(1)
  protected readonly totalCount = signal(0)
  protected readonly totalExact = signal(true)
  protected readonly hasNextPage = signal(false)
  protected readonly pendingCount = signal(0)
  protected readonly loaded = signal(false)
  // The row bars this view draws itself. The table bar is drawn by the room of live
  // messages above the rows (HilosTableLive), and the bulk bar lives inside the
  // selection panel and is drawn by the bar above the table — anywhere else it would
  // take the room the table bar gives to the project.
  protected readonly rowProgressBars = signal<
    ReadonlyMap<string, HilosTableProgressState>
  >(new Map())
  // A table whose count stopped at its ceiling has no page count to compare against, and
  // the footer is what such a table still needs: it is the only place saying there is more.
  protected readonly paginated = computed(() => {
    const pageCount = this.pageCount()

    return pageCount === null || pageCount > 1
  })
  // The total reads as "at least this many" when the count stopped at its ceiling, which is
  // what the trailing plus says.
  protected readonly countLabel = computed(() =>
    this.totalExact()
      ? `${this.totalCount()} total`
      : `${this.totalCount()}+ total`,
  )

  // The cells of a row bar's row, in the order the columns are declared: runs of
  // marked columns merge into one covered cell, runs of unmarked ones into one
  // empty cell, and the waiting cell is added exactly while it stands over the
  // ordinary rows. No column marked means one covered cell across the whole row.
  // The checkbox column takes an empty cell of its own on whichever edge it sits.
  //
  // The spans add up to bodyColspan by construction, which is what keeps the row
  // from growing wider than its header the moment a waiting change appears.
  protected readonly progressCells = computed<readonly ProgressCell[]>(() => {
    const columns = this.frameColumns()
    const cells: ProgressCell[] = []
    for (const column of columns) {
      const covered = column.progress === true
      const last = cells[cells.length - 1]
      if (last !== undefined && last.covered === covered) {
        last.span += 1
      } else {
        cells.push({ span: 1, covered })
      }
    }
    if (!columns.some((column) => column.progress === true)) {
      cells.splice(0, cells.length, { span: columns.length, covered: true })
    }
    if (this.markColumn()) {
      cells.push({ span: 1, covered: false })
    }
    if (this.selectionEnabled()) {
      const selectionCell: ProgressCell = { span: 1, covered: false }
      if (this.selectionEdge === 'start') {
        cells.unshift(selectionCell)
      } else {
        cells.push(selectionCell)
      }
    }

    return cells
  })

  constructor() {
    // The controller arrives via input (not at construction) and carries core
    // signals, so mirror them into the Angular signals above once it is bound;
    // the cleanup drops the subscriptions if the controller is replaced.
    effect((onCleanup) => {
      const controller = this.controller()
      const bind = <T>(
        source: ReadonlySignal<T>,
        target: WritableSignal<T>,
      ): (() => void) => {
        target.set(source.get())

        return subscribeSignal(source, (value) => target.set(value))
      }
      const subscriptions = [
        bind(controller.rows, this.rows),
        bind(controller.search, this.search),
        bind(controller.order, this.order),
        bind(controller.page, this.page),
        bind(controller.pageCount, this.pageCount),
        bind(controller.totalCount, this.totalCount),
        bind(controller.totalExact, this.totalExact),
        bind(controller.hasNextPage, this.hasNextPage),
        bind(controller.pendingCount, this.pendingCount),
        bind(controller.loaded, this.loaded),
        bind(controller.selection.header, this.selectionHeader),
        bind(controller.progress.rows, this.rowProgressBars),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  // A row's tint, resolved in the order the mockup resolves it (section 4): amber
  // while a pending change waits on the row — a move and a removal alike — and green
  // for the couple of seconds after a value landed. Waiting outranks the highlight,
  // because it is the one of the two the reader still has to act on. Red belongs to a
  // refused write, not to waiting. Bootstrap's contextual row classes carry their own
  // dark-mode variants, so they adapt to the active theme with no custom styles. An
  // untinted row gets the empty string, which is what the [class] binding takes.
  protected rowClass(view: TableViewportRow<R>): string {
    if (view.pending !== null) {
      return 'table-warning'
    }

    return view.highlighted ? 'table-success' : ''
  }

  // The arrow a header carries: every column the order runs by gets one, because
  // an order of two columns is sorted by both of them and a single arrow would
  // name one of the two as the whole answer.
  protected sortComponent(key: string): TableSort | undefined {
    return this.order()?.find((component) => component.field === key)
  }

  // The bar running over one row, or undefined when none is. Read out of the map
  // by key rather than off the row's own projection, which is the tie the channel
  // exists to cut (tableProgress.ts): the bar outlives the window the row sits in.
  protected rowBar(rowKey: string): HilosTableProgressState | undefined {
    return this.rowProgressBars().get(rowKey)
  }

  protected sortIcon(key: string): string {
    const component = this.sortComponent(key)
    if (component === undefined) {
      return 'bi-arrow-down-up text-muted'
    }

    return component.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
  }

  // A sortable header reports its current sort state to assistive tech through
  // aria-sort: a sortable-but-unsorted column reports 'none', the active column
  // its direction, and a non-sortable column nothing at all.
  protected ariaSort(
    column: HilosTableColumn,
  ): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.sortable) {
      return undefined
    }
    const component = this.sortComponent(column.key)
    if (component === undefined) {
      return 'none'
    }

    return component.direction === 'asc' ? 'ascending' : 'descending'
  }

  protected onSearchInput(event: Event): void {
    this.controller().setSearch((event.target as HTMLInputElement).value)
  }

  // The checkbox tells the core the state it is now IN rather than asking it to
  // toggle: the state of a checkbox is what the reader sees, and a toggle sent from
  // a box the browser has already flipped is a second answer to one question
  // (Flow F1).
  protected onSelectRow(rowKey: string, event: Event): void {
    this.controller().selectRow(
      rowKey,
      (event.target as HTMLInputElement).checked,
    )
  }

  // One input for both directions: the header box goes to "all" out of none and out
  // of the half-marked state alike, and clears the window only from "all" — which is
  // what the core does with the state the box is now in (Flow F2).
  protected onSelectPage(event: Event): void {
    this.controller().selectWindow((event.target as HTMLInputElement).checked)
  }
}
