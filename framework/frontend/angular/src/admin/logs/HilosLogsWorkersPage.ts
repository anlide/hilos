// HilosLogsWorkersPage draws the by-worker stream list. The core frame owns the
// shared bar, columns, cards, and footer; this page supplies cells and its four
// empty states. The header names nodes reactively, and Open leads to the stream viewer.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
  HilosPages,
  createHilosLogWorkersHeader,
  createHilosLogWorkersTable,
  formatLogWorkerState,
  formatLogWorkerType,
  formatLogWorkerWeight,
  hasLogWorkerNodes,
  logWorkerViewerPath,
  logWorkersEmptyState,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogWorkerRow,
  HilosLogWorkersContext,
  HilosLogWorkersHeader,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** The framework by-worker page: the worker stream list and its type distinction. */
@Component({
  selector: 'hilos-logs-workers-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <p class="text-body-secondary">
        The same thing again, but only for the workers and with the distinction
        the by-key page deliberately loses: an ordinary worker or the
        monopolistic one.
      </p>

      <hilos-viewport-table [controller]="streams().controller">
        <ng-template hilosTableCell="key" let-row>
          <code class="fw-semibold small">{{ row.key }}</code>
        </ng-template>
        <ng-template hilosTableCell="node" let-row>{{ row.node }}</ng-template>
        <ng-template hilosTableCell="type" let-row>
          <span [class]="'badge ' + typeClass(row)">
            {{ formatType(row) }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="live" let-row>
          <span [class]="'badge ' + stateClass(row)">
            {{ formatState(row) }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="batchCount" let-row>{{
          row.batchCount
        }}</ng-template>
        <ng-template hilosTableCell="bytes" let-row>{{
          formatWeight(row)
        }}</ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (viewerPath(row) !== '') {
            <a
              [hilosLink]="viewerPath(row)"
              class="btn btn-sm btn-outline-secondary text-nowrap"
              [attr.data-id]="'hilos-log-worker-open-' + row.rowKey"
            >
              Open
            </a>
          }
        </ng-template>

        <ng-template #empty>
          @if (emptyState() === 'unknown') {
            <div data-id="hilos-log-worker-empty-unknown">
              <div class="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p class="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          } @else if (emptyState() === 'unreadable') {
            <div data-id="hilos-log-worker-empty-unreadable">
              <div class="fw-semibold">The log directory cannot be read</div>
              <p class="mb-0">
                @if (clustered()) {
                  No node could read its log store.
                } @else {
                  The log store could not be read.
                }
                Check the log directory setting and the permissions on it.
              </p>
            </div>
          } @else if (emptyState() === 'nomatch') {
            <div data-id="hilos-log-worker-empty-nomatch">
              <div class="fw-semibold">Nothing matches</div>
              <p class="mb-2">There are worker streams — just not these.</p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="hilos-log-worker-clear-filters"
                (click)="clearFilters()"
              >
                Clear the filters
              </button>
            </div>
          } @else {
            <div data-id="hilos-log-worker-empty-never">
              <div class="fw-semibold">Nothing has been logged yet</div>
              <p class="mb-0">
                No worker has written into this directory — an installation that
                has only just come up looks exactly like this.
              </p>
            </div>
          }
        </ng-template>
      </hilos-viewport-table>

      <div class="alert alert-secondary small py-3 mt-4 mb-0">
        <div class="fw-semibold mb-1">
          <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Why a page of
          its own
        </div>
        @if (clustered()) {
          There is one monopolistic worker for the whole cluster and any number
          of ordinary ones, and they live on different machines. When the log
          has grown, the first question is whether all the workers grew or only
          the single one holding the shared work. The node column shows whose
          machine it is happening on.
        } @else {
          The monopolistic worker holds work that cannot be done by two hands at
          once. When the log has grown, the first question is whether the
          ordinary workers grew or that one did — they have different causes and
          different cures.
        }
      </div>
    </hilos-admin-page>
  `,
})
export class HilosLogsWorkersPage {
  /** The project context: scope stores and the connection. */
  readonly context = input.required<HilosLogWorkersContext>()

  protected readonly page = HilosPages.LOGS_WORKERS
  protected readonly formatState = formatLogWorkerState
  protected readonly formatType = formatLogWorkerType
  protected readonly formatWeight = formatLogWorkerWeight
  protected readonly viewerPath = logWorkerViewerPath

  private readonly headerHandle = computed(() =>
    createHilosLogWorkersHeader(this.context()),
  )
  protected readonly streams = computed(() =>
    createHilosLogWorkersTable(this.context(), this.headerHandle().header),
  )

  // The header and the window state, mirrored from the (per-context) core signals
  // into Angular signals so the template re-renders on every frame.
  protected readonly header = signal<HilosLogWorkersHeader | null>(null)
  private readonly rowCount = signal(0)
  private readonly search = signal('')
  private readonly filter = signal<Readonly<Record<string, unknown>>>({})

  // The node column, the node filter and the footnote's wording all follow the same
  // question: in a single-node installation a column repeating one name and a filter
  // offering one option would both be furniture for a choice that does not exist.
  protected readonly clustered = computed(() =>
    hasLogWorkerNodes(this.header()),
  )

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  protected readonly emptyState = computed(() =>
    logWorkersEmptyState(
      this.header(),
      this.rowCount(),
      this.search() !== '' || Object.keys(this.filter()).length > 0,
    ),
  )

  constructor() {
    // Bind the server-windowed table and start listening for the header once the
    // context input is bound; the header also arrives once as the answer to the
    // subscription. The same effect re-binds on a context swap and unbinds on destroy.
    effect((onCleanup) => {
      const streams = this.streams()
      const headerHandle = this.headerHandle()
      headerHandle.start()
      streams.start()
      this.header.set(headerHandle.header.get())
      this.rowCount.set(streams.controller.rows.get().length)
      this.search.set(streams.controller.search.get())
      this.filter.set(streams.controller.filter.get())
      const unsubscribes = [
        subscribeSignal(headerHandle.header, (next) => {
          this.header.set(next)
        }),
        subscribeSignal(streams.controller.rows, (next) => {
          this.rowCount.set(next.length)
        }),
        subscribeSignal(streams.controller.search, (next) => {
          this.search.set(next)
        }),
        subscribeSignal(streams.controller.filter, (next) => {
          this.filter.set(next)
        }),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribes) {
          unsubscribe()
        }
        streams.dispose()
        headerHandle.dispose()
      })
    })
  }

  // The monopolistic worker is the one this screen was opened for, so its badge is the
  // one that carries color; the ordinary ones stay quiet.
  protected typeClass(row: HilosLogWorkerRow): string {
    return row.type === HILOS_LOG_WORKER_TYPE_MONOPOLISTIC
      ? 'text-bg-info-subtle text-info-emphasis border border-info-subtle'
      : 'text-bg-light border'
  }

  // A stream still being written is the live one; one left only in the archive is
  // quiet, and the two are told apart by weight of color rather than by wording alone.
  protected stateClass(row: HilosLogWorkerRow): string {
    return row.live ? 'text-bg-success' : 'text-bg-light border'
  }

  protected clearFilters(): void {
    this.streams().controller.resetFilters()
  }
}
