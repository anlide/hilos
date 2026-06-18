// HilosTable — the thin Angular view over the core TableController. It renders the
// header (with per-column sort controls), the search box, the rows, and the
// pagination; it holds NO table logic — search, sort, and paging are the
// controller's (multiframework-core.md). Body cells come from an
// `<ng-template #row let-row let-rowKey="rowKey">` so the consumer keeps full
// control of each cell (links, badges, actions); the header, sorting, and paging
// stay framework-owned. An empty result renders an `<ng-template #empty>` or a
// default message. The controller arrives via input, carrying core signals, so
// the view mirrors them into Angular signals. Bootstrap classes only.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  input,
  signal,
} from '@angular/core'
import type { TemplateRef, WritableSignal } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type {
  HilosTableColumn,
  ReadonlySignal,
  TableController,
  TableSort,
  TableViewRow,
} from '@hilos/core'

/** The context a HilosTable `#row` template receives. */
export interface TableRowContext<R> {
  /** The resolved row view-model (the template's implicit `let-row`). */
  $implicit: R
  /** The row's stable key. */
  rowKey: string
}

/** The framework-owned table chrome over a headless TableController. */
@Component({
  selector: 'hilos-table',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  template: `
    <div data-id="hilos-table">
      @if (searchable()) {
        <div class="mb-3">
          <input
            type="search"
            class="form-control"
            [placeholder]="searchPlaceholder()"
            [value]="search()"
            data-id="hilos-table-search"
            (input)="onSearchInput($event)"
          />
        </div>
      }

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              @for (column of columns(); track column.key) {
                <th scope="col" [class]="column.headerClass ?? ''">
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
              <tr [attr.data-id]="'hilos-table-row-' + view.rowKey">
                <ng-container
                  [ngTemplateOutlet]="row()"
                  [ngTemplateOutletContext]="{
                    $implicit: view.row,
                    rowKey: view.rowKey,
                  }"
                />
              </tr>
            }
            @if (rows().length === 0) {
              <tr>
                <td
                  [attr.colspan]="columns().length"
                  class="text-center text-muted py-4"
                >
                  @if (empty(); as emptyTemplate) {
                    <ng-container [ngTemplateOutlet]="emptyTemplate" />
                  } @else {
                    {{ emptyText() }}
                  }
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>

      @if (paginated()) {
        <div class="d-flex justify-content-between align-items-center mt-3">
          <span class="text-muted small" data-id="hilos-table-count">
            {{ totalCount() }} total
          </span>
          <div class="btn-group" role="group" aria-label="Pagination">
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="page() === 0"
              data-id="hilos-table-prev"
              (click)="controller().setPage(page() - 1)"
            >
              Previous
            </button>
            <span class="btn btn-sm disabled" data-id="hilos-table-page">
              {{ page() + 1 }} / {{ pageCount() }}
            </span>
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="page() >= pageCount() - 1"
              data-id="hilos-table-next"
              (click)="controller().setPage(page() + 1)"
            >
              Next
            </button>
          </div>
        </div>
      }
    </div>
  `,
})
export class HilosTable<R> {
  /** The headless controller driving rows, search, sort, and paging. */
  readonly controller = input.required<TableController<R>>()
  /** Column declarations for the header (labels and sort controls). */
  readonly columns = input.required<HilosTableColumn[]>()
  /** Show the search box above the table. */
  readonly searchable = input(false)
  /** Placeholder for the search box. */
  readonly searchPlaceholder = input('Search…')
  /** Message shown when there are no rows. */
  readonly emptyText = input('No rows.')

  protected readonly row =
    contentChild.required<TemplateRef<TableRowContext<R>>>('row')
  protected readonly empty = contentChild<TemplateRef<unknown>>('empty')

  protected readonly rows = signal<readonly TableViewRow<R>[]>([])
  protected readonly search = signal('')
  protected readonly sort = signal<TableSort | undefined>(undefined)
  protected readonly page = signal(0)
  protected readonly pageCount = signal(1)
  protected readonly totalCount = signal(0)
  protected readonly pageSize = signal(0)
  protected readonly paginated = computed(
    () => this.pageSize() > 0 && this.pageCount() > 1,
  )

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
        bind(controller.pageRows, this.rows),
        bind(controller.search, this.search),
        bind(controller.sort, this.sort),
        bind(controller.page, this.page),
        bind(controller.pageCount, this.pageCount),
        bind(controller.totalCount, this.totalCount),
        bind(controller.pageSize, this.pageSize),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  protected sortIcon(key: string): string {
    const sort = this.sort()
    if (sort?.field !== key) {
      return 'bi-arrow-down-up text-muted'
    }

    return sort.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
  }

  protected onSearchInput(event: Event): void {
    this.controller().setSearch((event.target as HTMLInputElement).value)
  }
}
