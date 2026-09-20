// HilosTableEmptyState — the three states the body of a table says in words: the
// set is empty and nothing filters it ("data is not here yet"), it is empty under
// a search or a filter ("Nothing found"), or the WINDOW is empty over a set that
// is not ("Nothing on this page"). Which of the three holds is decided
// by the core (tableFrame.ts, HilosTableBody); this view only draws it, and the
// table draws it in both of its branches, wide and narrow. The first state speaks
// the page's own words — the title and hint of its declared empty state, and its
// main action, the same button the bar offers — while the second is the
// framework's and names what was searched for, because resetting is only an offer
// when the reader can see what will be reset (mockups/components/table section
// 10). The third is the framework's too and offers neither: the set has rows, so
// creating one answers nothing and there may be no filter to reset — what the
// reader needs is the way back to the rows.
// Internal to the Angular view layer on purpose: it is not exported from
// index.ts, for the reason the bar is not. The Angular port of the Vue reference
// (vue/src/HilosTableEmptyState.vue), under the same names and words.
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type { HilosTableFilterView, TableViewportController } from '@hilos/core'

/**
 * How one active filter reads in the sentence — the forms the control in the bar
 * already reads in (HilosTableFilterControl.ts): a dropdown names its option, a
 * date range leaves out a bound that is not there, a toggle is its own label.
 *
 * @param view The active filter to name.
 */
function filterTerm(view: HilosTableFilterView): string {
  const filter = view.filter
  if (filter.kind === 'select') {
    const option = filter
      .options()
      .find((declared) => declared.value === view.value)

    return `${filter.label}: ${option === undefined ? String(view.value) : option.label}`
  }
  if (filter.kind === 'date_range') {
    const { from, to } = view.value as { from?: unknown; to?: unknown }
    if (from !== undefined && to !== undefined) {
      return `${filter.label}: ${String(from)} – ${String(to)}`
    }
    if (from !== undefined) {
      return `${filter.label}: from ${String(from)}`
    }
    if (to !== undefined) {
      return `${filter.label}: until ${String(to)}`
    }
  }

  return filter.label
}

/** One worded state of the body of a table. */
@Component({
  selector: 'hilos-table-empty-state',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgTemplateOutlet],
  template: `
    @if (kind() === 'empty') {
      <div class="text-center py-4" role="status" data-id="hilos-table-empty">
        @if (declaration()?.empty; as empty) {
          <i
            class="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
            aria-hidden="true"
          ></i>
          <div
            class="fw-semibold small"
            [class.mb-1]="!!empty.hint"
            [class.mb-3]="!empty.hint"
            data-id="hilos-table-empty-title"
          >
            {{ empty.title }}
          </div>
          @if (empty.hint) {
            <p
              class="small text-body-secondary mb-3"
              data-id="hilos-table-empty-hint"
            >
              {{ empty.hint }}
            </p>
          }
        } @else {
          <!-- A table with no declared empty state still says what its page
          passed in the #empty template or the emptyText input: the framework's
          log pages hand their words over that way, and no leaf moves them onto
          the declaration yet. -->
          <div class="text-muted" [class.mb-3]="!!declaration()?.mainAction">
            <ng-container [ngTemplateOutlet]="fallback()" />
          </div>
        }
        @if (declaration()?.mainAction; as mainAction) {
          <button
            type="button"
            class="btn btn-sm btn-primary"
            data-id="hilos-table-empty-action"
            (click)="mainAction.press()"
          >
            {{ mainAction.label }}
          </button>
        }
      </div>
    } @else if (kind() === 'empty_page') {
      <div
        class="text-center py-4"
        role="status"
        data-id="hilos-table-empty-page"
      >
        <i
          class="bi bi-arrow-left-circle fs-1 text-body-secondary mb-2 d-block"
          aria-hidden="true"
        ></i>
        <div class="fw-semibold small mb-1">Nothing on this page</div>
        <p class="small text-body-secondary mb-3">
          These rows moved while the page was open.
        </p>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          data-id="hilos-table-empty-page-back"
          (click)="controller().prevPage()"
        >
          Back to the rows
        </button>
      </div>
    } @else {
      <div
        class="text-center py-4"
        role="status"
        data-id="hilos-table-no-matches"
      >
        <i
          class="bi bi-search fs-1 text-body-secondary mb-2 d-block"
          aria-hidden="true"
        ></i>
        <div class="fw-semibold small mb-1">Nothing found</div>
        <p
          class="small text-body-secondary mb-3 text-break"
          data-id="hilos-table-no-matches-terms"
        >
          {{ terms() }}
        </p>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          data-id="hilos-table-no-matches-reset"
          (click)="controller().resetFilters()"
        >
          Reset filters
        </button>
      </div>
    }
  `,
})
export class HilosTableEmptyState<R> {
  /** The headless server-windowed controller the state reads and resets. */
  readonly controller = input.required<TableViewportController<R>>()
  /** Which of the three worded states of the body to draw. */
  readonly kind = input.required<'empty' | 'empty_filtered' | 'empty_page'>()
  /**
   * The page's own words, standing when it declared no empty state. A template
   * rather than projected content: the tile stands in both branches of the table
   * at once, and content is projected only once.
   */
  readonly fallback = input.required<TemplateRef<unknown>>()

  // What the page declared about the frame, or null when it declared nothing. It
  // does not change over the life of a table, so it is read off the controller
  // rather than mirrored as a signal.
  protected readonly declaration = computed(
    () => this.controller().frame.declaration,
  )

  private readonly search = signal('')
  private readonly filters = signal<readonly HilosTableFilterView[]>([])

  // The query and the filters are named together: the reset below takes both off
  // with one press, and a sentence promising less than the button does would be
  // the wrong thing to read before pressing it.
  protected readonly terms = computed(() => {
    const parts: string[] = []
    const search = this.search()
    if (search !== '') {
      parts.push(`“${search}”`)
    }
    for (const view of this.filters()) {
      if (view.active) {
        parts.push(filterTerm(view))
      }
    }

    return `No rows match ${parts.join(' · ')}`
  })

  constructor() {
    // The controller arrives via input (not at construction) and carries core
    // signals, so the search and the filters are mirrored once it is bound; the
    // cleanup drops the subscriptions if the controller is replaced.
    effect((onCleanup) => {
      const search = this.controller().search
      this.search.set(search.get())
      onCleanup(subscribeSignal(search, (value) => this.search.set(value)))
    })
    effect((onCleanup) => {
      const filters = this.controller().frame.filters
      this.filters.set(filters.get())
      onCleanup(subscribeSignal(filters, (value) => this.filters.set(value)))
    })
  }
}
