// HilosViewportTable — the thin Angular view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row
// renders as a placeholder in its slot — the layout never collapses. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from an
// `<ng-template #row let-row let-rowKey="rowKey">`; the placeholder, header,
// paging, and the pending bar stay framework-owned. The controller arrives via
// input, carrying core signals, so the view mirrors them into Angular signals.
// (Distinct from HilosTable, the client-side view.) A table whose page DECLARED a
// frame draws the bar and the footer from that declaration instead
// (HilosTableBar, HilosTableFooter), and takes its columns, its name, and the words
// it says when empty from there too; a table whose page declared nothing is drawn
// from its inputs, the older of the two epochs, which the framework's log pages
// still take. Bootstrap classes only.
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
  ReadonlySignal,
  TableSort,
  TableSortOrder,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { HilosTableBar } from './HilosTableBar.js'
import { HilosTableFooter } from './HilosTableFooter.js'
import { HILOS_PAGE_HEADING_ID } from './hilosPageHeadingToken.js'

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

/** The framework-owned table chrome over a headless TableViewportController. */
@Component({
  selector: 'hilos-viewport-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosTableBar, HilosTableFooter, NgTemplateOutlet],
  template: `
    <div [attr.data-id]="dataId()">
      @if (declaration()) {
        <hilos-table-bar [controller]="controller()" [titleId]="titleId" />
      }

      <!-- The bar a table draws from its inputs, for a table whose page declared
      no frame — such as the framework's log pages. Its Apply control stays for a
      declared table too: the room of live messages that carries Apply in Vue is
      not ported yet (HIL-811…818), and without it pending changes would pile up
      with no way to show them. -->
      @if ((!declaration() && searchable()) || pendingCount() > 0) {
        <div
          class="d-flex justify-content-between align-items-center gap-2 mb-3"
        >
          @if (!declaration() && searchable()) {
            <input
              type="search"
              class="form-control"
              [placeholder]="searchPlaceholder()"
              [attr.aria-label]="searchPlaceholder()"
              [value]="search()"
              data-id="hilos-table-search"
              (input)="onSearchInput($event)"
            />
          }
          @if (pendingCount() > 0) {
            <button
              type="button"
              class="btn btn-primary btn-sm text-nowrap d-inline-flex align-items-center gap-2 ms-auto"
              [attr.aria-label]="'Apply ' + pendingCount() + ' pending changes'"
              data-id="hilos-table-apply"
              (click)="controller().apply()"
            >
              Apply changes
              <span
                class="badge text-bg-light"
                data-id="hilos-table-pending"
                aria-hidden="true"
              >
                {{ pendingCount() }}
              </span>
            </button>
          }
        </div>
      }

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
            </tr>
          </thead>
          <tbody>
            @for (view of rows(); track view.rowKey) {
              <tr
                [attr.data-id]="'hilos-table-row-' + view.rowKey"
                [class]="view.pending ? pendingRowClass[view.pending] : ''"
              >
                @if (view.placeholder) {
                  <td
                    [attr.colspan]="frameColumns().length"
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
              </tr>
            }
            @if (rows().length === 0) {
              <tr>
                <td
                  [attr.colspan]="frameColumns().length"
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

  // A row with an unapplied pending change gets a subtle, theme-aware tint that
  // stands out from the zebra striping: amber for a waiting move, red for a
  // waiting removal. Bootstrap's contextual row classes carry their own
  // dark-mode variants, so they adapt to the active theme with no custom styles.
  protected readonly pendingRowClass: Record<'move' | 'remove', string> = {
    move: 'table-warning',
    remove: 'table-danger',
  }

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
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  // The arrow a header carries: every column the order runs by gets one, because
  // an order of two columns is sorted by both of them and a single arrow would
  // name one of the two as the whole answer.
  protected sortComponent(key: string): TableSort | undefined {
    return this.order()?.find((component) => component.field === key)
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
}
