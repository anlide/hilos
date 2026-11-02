// Completed Status facts use the ordinary read-only viewport.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusHistoryState,
  createHilosStatusHistoryTable,
  hilosStatusHistoryDuration,
  HILOS_STATUS_COPY,
  HILOS_STATUS_HISTORY_COPY,
  STATUS_HISTORY_STARTED_AT_FIELD,
  STATUS_HISTORY_ENDED_AT_FIELD,
  STATUS_HISTORY_TITLE_FIELD,
  STATUS_HISTORY_OUTCOME_FIELD,
  type HilosStatusContext,
  type HilosStatusHistoryData,
  type HilosStatusHistoryRow,
} from '@hilos/core/admin/status'
import {
  HilosAdminPage,
  HilosViewportTable,
  HilosTableCell,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** Completed incidents and observed maintenance periods for the Status section. */
@Component({
  selector: 'hilos-status-history-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, HilosTableCell],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Status pages">
      <div class="hilos-stack mb-3">
        <p class="invisible mb-0" aria-hidden="true" inert>
          {{ historyCopy.unavailable }}
        </p>
        @if (data() === null) {
          <p
            class="text-body-secondary mb-0"
            data-id="hilos-status-history-unavailable"
          >
            {{ historyCopy.unavailable }}
          </p>
        }
      </div>
      @if (data() !== null) {
        <hilos-viewport-table
          [controller]="model().table.controller"
          data-id="hilos-status-history-table"
        >
          <ng-template [hilosTableCell]="startedAtField" let-row>
            {{ moment(row.startedAt) }}
          </ng-template>
          <ng-template [hilosTableCell]="endedAtField" let-row>
            {{ duration(row) }}
          </ng-template>
          <ng-template [hilosTableCell]="titleField" let-row>
            <span
              class="text-break"
              [attr.data-id]="'hilos-status-history-title-' + row.id"
            >
              @if (row.kind === 'incident') {
                {{ row.title }}
              } @else if (!row.announcementKnown) {
                Maintenance: announcement unknown
              } @else if (row.workPublicId === null) {
                Unannounced maintenance
              } @else {
                Maintenance «{{ row.title }}»
              }
            </span>
            @if (row.kind === 'incident') {
              <p class="small text-body-secondary mb-0">
                {{ severityLabel(row) }}
              </p>
            }
          </ng-template>
          <ng-template [hilosTableCell]="outcomeField" let-row>
            @if (row.outcome === 'resolved') {
              @if (row.publishedAt !== null) {
                Published at {{ moment(row.publishedAt) }}; resolved
                automatically
              } @else {
                Resolved automatically
              }
            } @else if (row.outcome === 'recovered_before_publication') {
              Not published: recovered before publication
            } @else if (row.outcome === 'canceled_publication') {
              Not published: publication canceled manually
            } @else if (row.endedAt !== null) {
              Completed
            } @else {
              Completion observed at {{ moment(row.completionObservedAt) }}
              <p class="small text-body-secondary mb-0">
                {{ historyCopy.unknownEnd }}
              </p>
            }
          </ng-template>
        </hilos-viewport-table>
      }
    </hilos-admin-page>
  `,
})
export class HilosStatusHistoryPage {
  /** The project's existing scope, connection and action lifecycle. */
  readonly context = input.required<HilosStatusContext>()

  protected readonly page = HilosPages.STATUS_HISTORY
  protected readonly copy = HILOS_STATUS_COPY
  protected readonly historyCopy = HILOS_STATUS_HISTORY_COPY
  protected readonly startedAtField = STATUS_HISTORY_STARTED_AT_FIELD
  protected readonly endedAtField = STATUS_HISTORY_ENDED_AT_FIELD
  protected readonly titleField = STATUS_HISTORY_TITLE_FIELD
  protected readonly outcomeField = STATUS_HISTORY_OUTCOME_FIELD
  protected readonly duration = hilosStatusHistoryDuration
  protected readonly data = signal<HilosStatusHistoryData>(null)
  private readonly timeZone = hilosSignal(hilosTimeZone)

  protected readonly model = computed(() => {
    const context = this.context()
    const state = createHilosStatusHistoryState(context)
    const table = createHilosStatusHistoryTable(context)
    return { state, table }
  })

  constructor() {
    effect((onCleanup) => {
      const { state, table } = this.model()
      const stopData = mirrorHilosSignal(state.data, this.data)
      state.start()
      table.start()
      onCleanup(() => {
        stopData()
        table.dispose()
        state.dispose()
      })
    })
  }

  /** Format an absolute server moment in the current reader's time zone. */
  protected moment(at: number): string {
    this.timeZone()
    return (
      formatMoment(at, {
        format: {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          timeZoneName: 'short',
        },
      }) ?? '—'
    )
  }

  /** Return the server-owned severity label for one incident row. */
  protected severityLabel(
    row: Extract<HilosStatusHistoryRow, { kind: 'incident' }>,
  ): string {
    return HILOS_STATUS_COPY[row.severity]
  }
}
