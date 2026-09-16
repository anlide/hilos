// HilosTableFooter — the strip below a table: which rows of the set are on screen,
// how many there are, and the two steps of the pager. It computes NOTHING of its
// own — the core hands over the numbers and the view builds the sentence in its own
// language (tableFrame.ts, HilosTableFooter). On an empty window it draws nothing
// at all: "0 of 0" says nothing, and the body of the table is what speaks about
// emptiness. Internal to the Angular view layer on purpose: it is not exported
// from index.ts, for the reason the bar is not. The Angular port of the Vue
// reference (vue/src/HilosTableFooter.vue), under the same names and words.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { subscribeSignal } from '@hilos/core'
import type {
  HilosTableFooter as HilosTableFooterState,
  TableViewportController,
} from '@hilos/core'

/** The declared strip below a table. */
@Component({
  selector: 'hilos-table-footer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (footer(); as footer) {
      @if (footer.firstRow > 0) {
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
          <span class="small text-body-secondary" data-id="hilos-table-count">
            {{ countLabel() }}
          </span>
          <div
            class="ms-auto btn-group btn-group-sm"
            role="group"
            aria-label="Pagination"
          >
            <button
              type="button"
              class="btn btn-outline-secondary"
              [disabled]="!footer.hasPreviousPage"
              data-id="hilos-table-prev"
              (click)="controller().prevPage()"
            >
              <i class="bi bi-chevron-left me-1" aria-hidden="true"></i>Previous
            </button>
            <button
              type="button"
              class="btn btn-outline-secondary"
              [disabled]="!footer.hasNextPage"
              data-id="hilos-table-next"
              (click)="controller().nextPage()"
            >
              Next<i class="bi bi-chevron-right ms-1" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      }
    }
  `,
})
export class HilosTableFooter<R> {
  /** The headless server-windowed controller the footer reads and pages. */
  readonly controller = input.required<TableViewportController<R>>()

  protected readonly footer = signal<HilosTableFooterState | null>(null)

  // The total reads as "at least this many" when the count stopped at its ceiling,
  // which is what the trailing plus says.
  protected readonly countLabel = computed(() => {
    const footer = this.footer()
    if (footer === null) {
      return ''
    }
    const { firstRow, lastRow, totalCount, totalExact } = footer

    return `${firstRow} – ${lastRow} of ${totalCount}${totalExact ? '' : '+'}`
  })

  constructor() {
    // The controller arrives via input (not at construction) and carries core
    // signals, so the footer is mirrored once it is bound; the cleanup drops the
    // subscription if the controller is replaced.
    effect((onCleanup) => {
      const source = this.controller().frame.footer
      this.footer.set(source.get())
      onCleanup(subscribeSignal(source, (value) => this.footer.set(value)))
    })
  }
}
