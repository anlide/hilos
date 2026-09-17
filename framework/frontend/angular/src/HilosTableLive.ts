// HilosTableLive — the one room above a table for everything live it has to say:
// changes waiting for Apply, rows created above the window, a source that stopped
// being kept up to date, and work running on the set. The room is exactly one line
// tall at every table and never changes height (styling-rules.md, "The room a live
// message takes"): an invisible twin of the very same row stands in the flow at all
// times and holds it, and the message is laid over that twin — over its OWN
// reserve, the way LoadingButton lays its spinner over its own text, and never over
// a row of the table. The twin stays in the flow rather than taking turns with the
// message, because the four rows are not one height: the freshness row has no
// button, and a room swapping to it would sit down.
// When several are live, the core decides which holds the line (tableLive.ts) and
// the others stand beside its text as their icons alone; a fifth kind of message
// arrives into this same room rather than a room of its own.
// What a screen reader hears is one hidden region that stands before there is
// anything to say, never the row itself: the track of running work lives in the row
// and moves every second, and a region on the row would read it out each time.
// Internal to the Angular view layer on purpose: it is not exported from index.ts,
// for the reason the bar and the footer are not — outside a table it means nothing.
// The controller arrives via input, carrying core signals, so the room mirrors them
// into Angular signals. The Angular port of the Vue reference
// (vue/src/HilosTableLive.vue), under the same names and words.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import type { WritableSignal } from '@angular/core'
import {
  hilosTableStaleColumns,
  hilosTableStaleLabel,
  hilosTableStaleSources,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosTableColumn,
  HilosTableLive as HilosTableLiveState,
  HilosTableLiveKind,
  HilosTableProgress as HilosTableProgressState,
  ReadonlySignal,
  TableViewportAnnounced,
  TableViewportController,
  TableViewportRow,
} from '@hilos/core'

import { HilosTableProgress } from './HilosTableProgress.js'

/**
 * The message row and its idle twin, to the character — only `invisible` on the
 * twin and the color and the placement on the message differ. The room equals the
 * true height of the row exactly while the markup matches. The row never wraps: the
 * text truncates and the icons and the button keep their size, so a narrow screen
 * cannot fold the button onto a second line.
 */
const ROW_CLASS =
  'alert py-2 px-3 mb-0 d-flex flex-nowrap align-items-center gap-2'

/** The color each kind of message is drawn in. */
const VARIANT: Record<HilosTableLiveKind, string> = {
  pending: 'alert-warning',
  announce: 'alert-secondary',
  stale: 'alert-info',
  progress: 'alert-secondary',
}

/** The icon each kind is drawn with — on the line when it holds it, and beside it when not. */
const ICON: Record<HilosTableLiveKind, string> = {
  pending: 'bi-pause-circle',
  announce: 'bi-arrow-down-circle',
  stale: 'bi-snow',
  progress: 'bi-arrow-repeat',
}

/** The data-id of the row while that kind holds it — the handles e2e reads each message by. */
const ROW_ID: Record<HilosTableLiveKind, string> = {
  pending: 'hilos-table-pending-row',
  announce: 'hilos-table-announce',
  stale: 'hilos-table-stale',
  progress: 'hilos-table-progress',
}

/** How each kind is named when it is listed after the one holding the line. */
const REST_WORDS: Record<HilosTableLiveKind, string> = {
  pending: 'pending changes',
  announce: 'new rows above the window',
  stale: 'a source is behind',
  progress: 'work running',
}

/** The room of live messages above one table. */
@Component({
  selector: 'hilos-table-live',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosTableProgress],
  template: `
    <div class="position-relative mb-2" data-id="hilos-table-live-slot">
      <div
        [class]="twinClass"
        aria-hidden="true"
        data-id="hilos-table-live-idle"
      >
        <i class="bi bi-info-circle flex-shrink-0"></i>
        <span class="small flex-grow-1 text-truncate">&nbsp;</span>
        <!-- A span, not a button: the twin holds room, it does not take focus. -->
        <span class="btn btn-sm btn-outline-secondary flex-shrink-0"
          >&nbsp;</span
        >
      </div>

      @if (live().top; as top) {
        <div [class]="lineClass(top)" [attr.data-id]="rowId[top]">
          <i [class]="'bi flex-shrink-0 ' + icon[top]" aria-hidden="true"></i>
          <span class="small flex-grow-1 text-truncate">
            @if (top === 'pending') {
              <span data-id="hilos-table-pending">{{ pendingCount() }}</span>
              {{ pendingSuffix() }}
            } @else if (top === 'announce') {
              {{ announceLabel() }}
            } @else if (top === 'stale') {
              {{ staleLabel() }}
            }
          </span>
          @if (live().rest.length > 0) {
            <span
              class="d-flex gap-1 flex-shrink-0"
              data-id="hilos-table-live-rest"
            >
              @for (kind of live().rest; track kind) {
                <i
                  [class]="'bi flex-shrink-0 ' + icon[kind]"
                  aria-hidden="true"
                ></i>
              }
            </span>
          }
          @if (top === 'pending') {
            <button
              type="button"
              class="btn btn-sm btn-warning flex-shrink-0"
              data-id="hilos-table-apply"
              (click)="controller().apply()"
            >
              Apply
            </button>
          } @else if (top === 'announce') {
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary flex-shrink-0"
              data-id="hilos-table-announce-show"
              (click)="controller().show()"
            >
              Show
            </button>
          } @else if (top === 'progress' && tableProgress(); as progress) {
            <!-- The track runs along the bottom edge of the line and adds no height;
            the line is positioned itself, so it is the box the track sits in. -->
            <hilos-table-progress
              class="position-absolute bottom-0 start-0 w-100 rounded-0"
              [progress]="progress"
              label="Work on this table"
            />
          }
        </div>
      }

      <span
        class="visually-hidden"
        role="status"
        data-id="hilos-table-live-status"
        >{{ announcement() }}</span
      >
    </div>
  `,
})
export class HilosTableLive<R> {
  /** The headless server-windowed controller the room reads and drives. */
  readonly controller = input.required<TableViewportController<R>>()
  /**
   * The columns the table is drawn from — read to name the columns built from a
   * source that went quiet, so the room cannot name a column the table does not have.
   */
  readonly columns = input.required<readonly HilosTableColumn[]>()

  protected readonly twinClass = `${ROW_CLASS} alert-secondary invisible`
  protected readonly icon = ICON
  protected readonly rowId = ROW_ID

  protected readonly live = signal<HilosTableLiveState>({ top: null, rest: [] })
  private readonly rows = signal<readonly TableViewportRow<R>[]>([])
  protected readonly pendingCount = signal(0)
  private readonly announced = signal<TableViewportAnnounced>({
    above: 0,
    inside: 0,
    total: 0,
  })
  protected readonly tableProgress = signal<HilosTableProgressState | null>(
    null,
  )

  // The sentence about the columns that went quiet, read out of the very list the
  // table is drawn from.
  protected readonly staleLabel = computed(() => {
    const sources = hilosTableStaleSources(this.rows())

    return hilosTableStaleLabel(
      hilosTableStaleColumns(this.columns(), sources),
      sources.size > 0,
    )
  })

  // The numeral of each message is chosen here rather than in the template: '1 rows'
  // would stand in the most visible place of the screen.
  protected readonly announceLabel = computed(() =>
    this.announced().above === 1
      ? '1 new row above the window'
      : `${this.announced().above} new rows above the window`,
  )

  // Only the tail of the waiting sentence is composed here, because the count itself
  // stays a node of its own under `hilos-table-pending` — the handle the outside reads
  // the number by.
  protected readonly pendingSuffix = computed(() =>
    this.pendingCount() === 1
      ? 'row will move or leave'
      : 'rows will move or leave',
  )

  // What the hidden region says: the sentence of the message on the line, then the
  // others by name. The track of running work is not in it — it moves every second.
  protected readonly announcement = computed(() => {
    const top = this.live().top
    if (top === null) {
      return ''
    }
    const sentences: Record<HilosTableLiveKind, string> = {
      pending: `${this.pendingCount()} ${this.pendingSuffix()}.`,
      announce: `${this.announceLabel()}.`,
      stale: this.staleLabel() ?? '',
      progress: 'Work is running on this table.',
    }
    const rest = this.live().rest.map((kind) => REST_WORDS[kind])

    return rest.length === 0
      ? sentences[top]
      : `${sentences[top]} Also: ${rest.join(', ')}.`
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
        bind(controller.live, this.live),
        bind(controller.rows, this.rows),
        bind(controller.pendingCount, this.pendingCount),
        bind(controller.announced, this.announced),
        bind(controller.progress.table, this.tableProgress),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })
  }

  // The message row: the twin's layout, the color of its kind, and laid over the twin.
  protected lineClass(top: HilosTableLiveKind): string {
    return `${ROW_CLASS} ${VARIANT[top]} position-absolute top-0 start-0 w-100`
  }
}
