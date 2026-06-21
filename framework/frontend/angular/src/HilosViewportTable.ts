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
// (Distinct from HilosTable, the client-side view.) Bootstrap classes only.
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
  TableSort,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

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
  imports: [NgTemplateOutlet],
  template: `
    <div data-id="hilos-viewport-table">
      @if (searchable() || pendingCount() > 0) {
        <div
          class="d-flex justify-content-between align-items-center gap-2 mb-3"
        >
          @if (searchable()) {
            <input
              type="search"
              class="form-control"
              [placeholder]="searchPlaceholder()"
              [value]="search()"
              data-id="hilos-table-search"
              (input)="onSearchInput($event)"
            />
          }
          @if (pendingCount() > 0) {
            <button
              type="button"
              class="btn btn-primary btn-sm text-nowrap d-inline-flex align-items-center gap-2 ms-auto"
              data-id="hilos-table-apply"
              (click)="controller().apply()"
            >
              Apply changes
              <span class="badge text-bg-light" data-id="hilos-table-pending">
                {{ pendingCount() }}
              </span>
            </button>
          }
        </div>
      }

      @if (listChanged()) {
        <div
          class="alert alert-warning py-2"
          role="status"
          data-id="hilos-table-list-changed"
        >
          The list changed — apply to refresh.
        </div>
      }

      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
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
              <tr
                [attr.data-id]="'hilos-table-row-' + view.rowKey"
                [class]="view.pending ? pendingRowClass[view.pending] : ''"
              >
                @if (view.placeholder) {
                  <td
                    [attr.colspan]="columns().length"
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
                  [attr.colspan]="columns().length"
                  class="text-center text-muted py-4"
                >
                  @if (!loaded()) {
                    <span
                      class="d-inline-flex align-items-center gap-2"
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
export class HilosViewportTable<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  readonly controller = input.required<TableViewportController<R>>()
  /** Column declarations for the header (labels and sort controls). */
  readonly columns = input.required<HilosTableColumn[]>()
  /** Show the search box above the table. */
  readonly searchable = input(false)
  /** Placeholder for the search box. */
  readonly searchPlaceholder = input('Search…')
  /** Message shown when there are no rows. */
  readonly emptyText = input('No rows.')
  /** Message shown while the first window is still loading. */
  readonly loadingText = input('Loading…')
  /** Label shown in a removed row's placeholder slot. */
  readonly placeholderText = input('Removed')

  protected readonly row =
    contentChild.required<TemplateRef<ViewportTableRowContext<R>>>('row')
  protected readonly empty = contentChild<TemplateRef<unknown>>('empty')

  protected readonly rows = signal<readonly TableViewportRow<R>[]>([])
  protected readonly search = signal('')
  protected readonly sort = signal<TableSort | undefined>(undefined)
  protected readonly page = signal(0)
  protected readonly pageCount = signal(1)
  protected readonly totalCount = signal(0)
  protected readonly pendingCount = signal(0)
  protected readonly listChanged = signal(false)
  protected readonly loaded = signal(false)
  protected readonly paginated = computed(() => this.pageCount() > 1)

  // A row with an unapplied pending change gets a subtle, theme-aware tint that
  // stands out from the zebra striping: amber for a waiting update, red for a
  // waiting removal. Bootstrap's contextual row classes carry their own
  // dark-mode variants, so they adapt to the active theme with no custom styles.
  protected readonly pendingRowClass: Record<'update' | 'remove', string> = {
    update: 'table-warning',
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
        bind(controller.sort, this.sort),
        bind(controller.page, this.page),
        bind(controller.pageCount, this.pageCount),
        bind(controller.totalCount, this.totalCount),
        bind(controller.pendingCount, this.pendingCount),
        bind(controller.listChanged, this.listChanged),
        bind(controller.loaded, this.loaded),
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
