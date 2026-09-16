// HilosTableBar — the strip above a table, drawn from what the page DECLARED
// (HilosTableFrame) and never from inputs of its own: the title and subtitle, the
// search box, the declared filters, and the one main action pinned right. It holds
// NO table logic — the controller owns the descriptor, and every control here is a
// call into it (multiframework-core.md). Internal to the Angular view layer on
// purpose: it is not exported from index.ts, because a bar has no meaning away
// from the table it sits on (mockups/components/table section 7). The Angular port
// of the Vue reference (vue/src/HilosTableBar.vue), under the same names and words.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import type { WritableSignal } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type {
  HilosTableFilterView,
  ReadonlySignal,
  TableViewportController,
} from '@hilos/core'

import { HilosModal } from './HilosModal.js'
import { HilosTableFilterControl } from './HilosTableFilterControl.js'

/** The declared strip above a table. */
@Component({
  selector: 'hilos-table-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModal, HilosTableFilterControl],
  template: `
    <div>
      <div class="mb-2">
        <h2 [id]="titleId()" class="h6 mb-0" data-id="hilos-table-title">
          {{ title() }}
        </h2>
        @if (subtitle(); as subtitle) {
          <p
            class="small text-body-secondary mb-0"
            data-id="hilos-table-subtitle"
          >
            {{ subtitle }}
          </p>
        }
      </div>

      @if (searchBox() || filters().length > 0 || mainAction()) {
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
          @if (searchBox()) {
            <div
              class="input-group input-group-sm w-auto flex-grow-1 flex-md-grow-0"
            >
              <span class="input-group-text">
                <i class="bi bi-search" aria-hidden="true"></i>
              </span>
              <input
                type="search"
                class="form-control"
                [placeholder]="searchPlaceholder()"
                [attr.aria-label]="searchPlaceholder()"
                [value]="search()"
                data-id="hilos-table-search"
                (input)="onSearchInput($event)"
              />
              @if (search() !== '') {
                <button
                  type="button"
                  class="btn btn-outline-secondary"
                  aria-label="Clear search"
                  data-id="hilos-table-search-clear"
                  (click)="controller().setSearch('')"
                >
                  <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
              }
            </div>
          }

          <!-- Below md the controls leave the bar for the modal below, and the
          button that opens it takes their place. The search does not go with
          them: it is the main way to narrow a set, and hiding it behind a button
          would hide exactly what the table was opened for. -->
          @if (filters().length > 0) {
            <div class="d-none d-md-flex flex-wrap align-items-center gap-2">
              @for (view of filters(); track filterKey(view)) {
                <hilos-table-filter-control
                  [view]="view"
                  [controller]="controller()"
                  placement="bar"
                />
              }
            </div>
          }

          @if (activeFilterCount() > 0) {
            <span
              class="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              data-id="hilos-table-filter-badge"
            >
              {{ filterCountLabel() }}
              <button
                type="button"
                class="btn-close ms-1"
                aria-label="Reset filters"
                data-id="hilos-table-filter-reset"
                (click)="controller().resetFilters()"
              ></button>
            </span>
          }

          <!-- The button says only its word: the number of filters holding a
          value is said once, on the badge above, which stays on a narrow screen
          because the reset lives nowhere else. -->
          @if (filters().length > 0) {
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary d-md-none"
              data-id="hilos-table-filters-open"
              (click)="filtersOpen.set(true)"
            >
              Filters
            </button>
          }

          @if (mainAction(); as mainAction) {
            <button
              type="button"
              class="btn btn-sm btn-primary ms-auto"
              data-id="hilos-table-main-action"
              (click)="mainAction.press()"
            >
              {{ mainAction.label }}
            </button>
          }
        </div>
      }

      <!-- Under the same condition as the button that opens it: a table with no
      filters has nothing to show here, and a dialog no button can reach would be
      one more owner of the page's scroll lock for nobody. -->
      @if (filters().length > 0) {
        <hilos-modal
          [(open)]="filtersOpen"
          title="Filters"
          initialFocus="inner"
        >
          <div class="d-flex flex-column gap-3">
            @for (view of filters(); track filterKey(view)) {
              <hilos-table-filter-control
                [view]="view"
                [controller]="controller()"
                placement="modal"
              />
            }
          </div>
          <ng-template #modalActions let-requestClose="requestClose">
            <button
              type="button"
              class="btn btn-primary"
              data-id="hilos-table-filters-done"
              (click)="requestClose()"
            >
              Done
            </button>
          </ng-template>
        </hilos-modal>
      }
    </div>
  `,
})
export class HilosTableBar<R> {
  /** The headless server-windowed controller the bar reads and drives. */
  readonly controller = input.required<TableViewportController<R>>()
  /**
   * The id the title carries, so the table below can name itself with
   * aria-labelledby: the accessible name of the table is the very heading a
   * sighted person reads, and the id has to be minted where both of them can see
   * it.
   */
  readonly titleId = input.required<string>()

  // The declaration does not change over the life of a table, so its parts are
  // read off it rather than mirrored as signals (tableFrame.ts,
  // HilosTableFrameState); they follow only the controller input itself.
  private readonly declaration = computed(
    () => this.controller().frame.declaration,
  )
  protected readonly title = computed(() => this.declaration()?.title ?? '')
  protected readonly subtitle = computed(() => this.declaration()?.subtitle)
  protected readonly searchBox = computed(() => this.declaration()?.search)
  protected readonly mainAction = computed(() => this.declaration()?.mainAction)

  // The search placeholder doubles as the field's accessible name: the field
  // carries no visible label, and two different strings would name it twice.
  protected readonly searchPlaceholder = computed(
    () => this.searchBox()?.placeholder ?? 'Search…',
  )

  protected readonly search = signal('')
  protected readonly filters = signal<readonly HilosTableFilterView[]>([])
  protected readonly activeFilterCount = signal(0)

  // The badge counts the declared filters holding a value; the search box is not
  // one of them and has its own field (tableFrame.ts, activeFilterCount).
  protected readonly filterCountLabel = computed(() =>
    this.activeFilterCount() === 1
      ? '1 filter'
      : `${this.activeFilterCount()} filters`,
  )

  // Narrow screens put the filters in a modal rather than an offcanvas of the
  // SDK's own: the SDK ships Bootstrap's CSS and not its JS, so an offcanvas would
  // be a second way of showing a surface over the screen — one every other view
  // layer would then have to repeat.
  protected readonly filtersOpen = signal(false)

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
        bind(controller.search, this.search),
        bind(controller.frame.filters, this.filters),
        bind(controller.frame.activeFilterCount, this.activeFilterCount),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  // A date range is one control over two keys, and it is listed under the lower
  // one — the same word the control answers to from outside.
  protected filterKey(view: HilosTableFilterView): string {
    return view.filter.kind === 'date_range'
      ? view.filter.fromKey
      : view.filter.key
  }

  protected onSearchInput(event: Event): void {
    this.controller().setSearch((event.target as HTMLInputElement).value)
  }
}
