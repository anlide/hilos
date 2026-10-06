// HilosLogsKeysPage draws the by-key stream list. The core frame owns the shared
// bar, columns, cards, and footer; this page supplies cells and its four empty
// states. The header names nodes reactively, and Open leads to the stream viewer.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HILOS_PAGE_ROUTES,
  HilosPages,
  createHilosLogKeysHeader,
  createHilosLogKeysTable,
  formatLogKeyClass,
  formatLogKeyGrowth,
  formatLogKeyState,
  formatLogKeyWeight,
  hasLogKeyNodes,
  logKeyViewerPath,
  logKeysEmptyState,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosLogKeyRow,
  HilosLogKeysContext,
  HilosLogKeysHeader,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** The framework by-key page: the stream list with its filters and empty states. */
@Component({
  selector: 'hilos-logs-keys-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <p class="text-body-secondary">
        A key is the file name that survives rotation: the same stream goes on
        being written under that name into the next batch.
        @if (clustered()) {
          A row here is a key <em>on a node</em>: the same
          <code>worker-0.log</code> on two nodes is two files, carried off
          apart.
        }
      </p>

      <hilos-viewport-table [controller]="streams().controller">
        <ng-template hilosTableCell="key" let-row>
          <code class="fw-semibold small">{{ row.key }}</code>
        </ng-template>
        <ng-template hilosTableCell="node" let-row>{{ row.node }}</ng-template>
        <ng-template hilosTableCell="class" let-row>
          <span
            class="badge text-bg-secondary-subtle text-secondary-emphasis border"
            >{{ formatClass(row) }}</span
          >
        </ng-template>
        <ng-template hilosTableCell="live" let-row>
          <span [class]="'badge ' + stateClass(row)">{{
            formatState(row)
          }}</span>
        </ng-template>
        <ng-template hilosTableCell="batchCount" let-row>{{
          row.batchCount
        }}</ng-template>
        <ng-template hilosTableCell="bytes" let-row>{{
          formatWeight(row)
        }}</ng-template>
        <ng-template hilosTableCell="growthPerDay" let-row>{{
          formatGrowth(row)
        }}</ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (viewerPath(row) !== '') {
            <a
              [hilosLink]="viewerPath(row)"
              class="btn btn-sm btn-outline-secondary text-nowrap"
              [attr.data-id]="'hilos-log-key-open-' + row.rowKey"
              >Open</a
            >
          }
        </ng-template>

        <ng-template #empty>
          @if (emptyState() === 'unknown') {
            <div data-id="hilos-log-key-empty-unknown">
              <div class="fw-semibold">
                The cluster picture has not arrived yet
              </div>
              <p class="mb-0">
                Nobody has reported yet, so there are no figures — not zero of
                them.
              </p>
            </div>
          } @else if (emptyState() === 'unreadable') {
            <div data-id="hilos-log-key-empty-unreadable">
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
            <div data-id="hilos-log-key-empty-nomatch">
              <div class="fw-semibold">Nothing matches</div>
              <p class="mb-2">There are streams — just not these.</p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="hilos-log-key-clear-filters"
                (click)="clearFilters()"
              >
                Clear the filters
              </button>
            </div>
          } @else {
            <div data-id="hilos-log-key-empty-never">
              <div class="fw-semibold">Nothing has been logged yet</div>
              <p class="mb-0">
                The daemon has not written into this directory — an installation
                that has only just come up looks exactly like this.
              </p>
            </div>
          }
        </ng-template>
      </hilos-viewport-table>

      <!-- The legend of the three classes reads no data and has no state, so it
      stands even under an empty table: it says what the files behind the class
      switch are. -->
      <section data-id="hilos-log-key-classes">
        <h2 class="h6 text-uppercase text-body-secondary mb-2 mt-4">
          Three stream classes, and there will be no fourth
        </h2>
        <p class="small text-body-secondary">
          The class is a property of which process writes the file, not of how
          the screen shows it. There are exactly three, and the All button is
          the sum of the other three.
        </p>
        <div class="row row-cols-1 row-cols-lg-3 g-3">
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div class="d-flex align-items-center gap-2 mb-1">
                <i
                  class="bi bi-hdd-stack text-body-secondary"
                  aria-hidden="true"
                ></i>
                <span class="badge text-bg-light border">Daemon</span>
              </div>
              <div class="small mb-2">
                <code>daemon.log</code>, <code>daemon-error.log</code> and the
                raw stream beside each
              </div>
              <p class="small text-body-secondary mb-0">
                Matched by the exact file name rather than by a prefix: the
                daemon's own streams carry no prefix at all. There are four
                files, not two — beside the one the logger writes lies a raw
                stream taking everything PHP prints past it: a fatal error, a
                warning, a trace. Rotation does not touch the raw pair; only a
                daemon restart replaces it.
              </p>
            </div>
          </div>
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-cpu text-body-secondary" aria-hidden="true"></i>
                <span class="badge text-bg-light border">Agents</span>
              </div>
              <div class="small mb-2"><code>agent-*.log</code></div>
              <p class="small text-body-secondary mb-0">
                One file per agent, the stream named after the agent type.
              </p>
            </div>
          </div>
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div class="d-flex align-items-center gap-2 mb-1">
                <i
                  class="bi bi-diagram-3 text-body-secondary"
                  aria-hidden="true"
                ></i>
                <span class="badge text-bg-light border">Workers</span>
              </div>
              <div class="small mb-2">
                <code>worker-*.log</code> and
                <code>worker-monopolistic-*.log</code>
              </div>
              <p class="small text-body-secondary mb-0">
                Both prefixes are folded into one class: telling ordinary
                workers from monopolistic ones is the neighbouring page's
                question, and here it would only split the list.
              </p>
            </div>
          </div>
        </div>
        <p class="small text-body-secondary mt-3 mb-0">
          The daemon's streams are where the errors that bring anyone to this
          section land. Hiding them here would keep a whole class of logs from
          the operator.
          @if (clustered()) {
            Each node has its own four, and they are carried off apart.
          }
        </p>
      </section>

      <p class="small text-body-secondary mt-3 mb-0">
        The weight answers "how much is taken", the growth answers "when the
        room runs out"; a stream that is no longer written has no growth.
        Monopolistic workers are folded in with the ordinary ones here — the
        split is shown by
        <a [hilosLink]="workersPath" data-id="hilos-log-key-workers-link">
          the workers page </a
        >. Search and sorting go to the server: while it counts, the table is
        busy rather than showing the old order as the new one.
      </p>
    </hilos-admin-page>
  `,
})
export class HilosLogsKeysPage {
  /** The project context: scope stores and the connection. */
  readonly context = input.required<HilosLogKeysContext>()

  protected readonly page = HilosPages.LOGS_KEYS
  protected readonly formatClass = formatLogKeyClass
  protected readonly formatGrowth = formatLogKeyGrowth
  protected readonly formatState = formatLogKeyState
  protected readonly formatWeight = formatLogKeyWeight
  protected readonly viewerPath = logKeyViewerPath

  // Where the split this page folds away is actually shown. HIL-385 left the phrase
  // as plain text because the by-worker page was still a stub; it is a screen now.
  protected readonly workersPath = HILOS_PAGE_ROUTES[HilosPages.LOGS_WORKERS]

  private readonly headerHandle = computed(() =>
    createHilosLogKeysHeader(this.context()),
  )
  protected readonly streams = computed(() =>
    createHilosLogKeysTable(this.context(), this.headerHandle().header),
  )

  // The header and the window state, mirrored from the (per-context) core signals
  // into Angular signals so the template re-renders on every frame.
  protected readonly header = signal<HilosLogKeysHeader | null>(null)
  private readonly rowCount = signal(0)
  private readonly search = signal('')

  private readonly filter = signal<Readonly<Record<string, unknown>>>({})
  protected readonly clustered = computed(() => hasLogKeyNodes(this.header()))

  // Which of the four empty states the screen is in — the discrimination is the
  // headless's, because it is the same question in all three view frameworks.
  protected readonly emptyState = computed(() =>
    logKeysEmptyState(
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

  // A stream still being written is the live one; one left only in the archive is
  // quiet, and the two are told apart by weight of color rather than by wording alone.
  protected stateClass(row: HilosLogKeyRow): string {
    return row.live
      ? 'text-bg-success'
      : 'bg-body-tertiary text-body-secondary border'
  }

  protected clearFilters(): void {
    this.streams().controller.resetFilters()
  }
}
