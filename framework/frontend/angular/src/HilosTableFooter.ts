// HilosTableFooter — the strip below a table: which rows of the set are on screen,
// how many there are, and the pager. It computes NOTHING about the set — the core
// hands over the numbers and the view builds the sentence in its own language
// (tableFrame.ts, HilosTableFooter); the one thing it works out is which page
// numbers fit in the row, which is a question about the width of a strip and
// belongs to nobody else. Page numbers stand there exactly while the count is
// exact: a page is a place counted from an end of the set, and a count stopped at
// its ceiling has no such end (mockups/components/table section 8). On an empty
// window it draws nothing at all: "0 of 0" says nothing, and the body of the table
// is what speaks about emptiness. Internal to the Angular view layer on purpose:
// it is not exported from index.ts, for the reason the bar is not. The Angular
// port of the Vue reference (vue/src/HilosTableFooter.vue), under the same names
// and words.
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

/**
 * One slot of the pager: a page to go to, or the pages passed over between two
 * of them.
 */
type PagerSlot = { kind: 'page'; number: number } | { kind: 'gap' }

/**
 * How many slots the pager holds — five numbers and the two stretches passed
 * over between them. A set that fits in that many pages is drawn whole instead:
 * skipping over pages there would save no room and only hide where the reader
 * can go.
 */
const PAGER_SLOTS = 7

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
          @if (footer.paginated) {
            <div
              class="ms-auto btn-group btn-group-sm"
              role="group"
              aria-label="Pagination"
            >
              <button
                type="button"
                class="btn btn-outline-secondary"
                [disabled]="!footer.hasPreviousPage"
                [attr.aria-label]="pages().length > 0 ? 'Previous page' : null"
                data-id="hilos-table-prev"
                (click)="controller().prevPage()"
              >
                <i
                  class="bi bi-chevron-left"
                  [class.me-1]="pages().length === 0"
                  aria-hidden="true"
                ></i>
                @if (pages().length === 0) {
                  Previous
                }
              </button>
              @for (slot of pages(); track slotKey(slot, $index)) {
                @if (slot.kind === 'page' && slot.number !== footer.page + 1) {
                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    [attr.aria-label]="'Page ' + slot.number"
                    [attr.data-id]="'hilos-table-page-' + slot.number"
                    (click)="controller().setPage(slot.number - 1)"
                  >
                    {{ slot.number }}
                  </button>
                } @else if (slot.kind === 'page') {
                  <button
                    type="button"
                    class="btn btn-outline-secondary active"
                    aria-current="page"
                    disabled
                    [attr.data-id]="'hilos-table-page-' + slot.number"
                  >
                    {{ slot.number }}
                  </button>
                } @else {
                  <span
                    class="btn btn-outline-secondary disabled"
                    aria-hidden="true"
                    >…</span
                  >
                }
              }
              <button
                type="button"
                class="btn btn-outline-secondary"
                [disabled]="!footer.hasNextPage"
                [attr.aria-label]="pages().length > 0 ? 'Next page' : null"
                data-id="hilos-table-next"
                (click)="controller().nextPage()"
              >
                @if (pages().length === 0) {
                  Next
                }
                <i
                  class="bi bi-chevron-right"
                  [class.ms-1]="pages().length === 0"
                  aria-hidden="true"
                ></i>
              </button>
            </div>
          }
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

  // The pager's slots: the first page, the last one, and the current page with its
  // two neighbors — the places a reader can reach in one step. Two held numbers
  // with a single page between them keep that page rather than an ellipsis
  // standing in for it, which would take the same room and offer less.
  protected readonly pages = computed<readonly PagerSlot[]>(() => {
    const footer = this.footer()
    if (footer === null || footer.pageCount === null) {
      return []
    }
    const { page, pageCount } = footer
    if (pageCount <= PAGER_SLOTS) {
      return Array.from({ length: pageCount }, (_unused, index) => ({
        kind: 'page',
        number: index + 1,
      }))
    }

    const current = page + 1
    const held = [...new Set([1, current - 1, current, current + 1, pageCount])]
      .filter((number) => number >= 1 && number <= pageCount)
      .sort((one, other) => one - other)

    return held.flatMap<PagerSlot>((number, index) => {
      const previous = held[index - 1]
      if (previous === undefined || number - previous === 1) {
        return [{ kind: 'page', number }]
      }

      return [
        number - previous === 2
          ? { kind: 'page', number: number - 1 }
          : { kind: 'gap' },
        { kind: 'page', number },
      ]
    })
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

  // A page is tracked by its number and a gap by where it stands, the key the Vue
  // reference gives each slot.
  protected slotKey(slot: PagerSlot, index: number): string {
    return slot.kind === 'page' ? `page-${slot.number}` : `gap-${index}`
  }
}
