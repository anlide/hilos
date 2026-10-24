import { NgClass } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  HilosViewportTable,
  HilosTableCell,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'
import {
  createHilosDaemonHttpRoutesTable,
  daemonHttpEmptyState,
  DAEMON_NODE_STATE_SILENT,
  formatDaemonHttpPeriod,
  formatDaemonNodeSilentSince,
  readHilosDaemonHttpSummary,
  readHilosDaemonNodeHeading,
  DAEMON_HTTP_ROUTE_PATH_FIELD,
  DAEMON_HTTP_ROUTE_REQUESTS_FIELD,
  DAEMON_HTTP_ROUTE_ERRORS_FIELD,
  DAEMON_HTTP_ROUTE_SLOWEST_FIELD,
  formatDaemonHttpAddress,
  formatDaemonHttpCount,
  formatDaemonHttpRoute,
  formatDaemonHttpSlowest,
  type HilosDaemonHttpContext,
  type HilosDaemonNodeHeading,
  type HilosDaemonHttpSummary,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'

/** Angular view of the Daemon HttpServerPage. */
@Component({
  selector: 'hilos-daemon-http-server-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgClass, HilosViewportTable, HilosTableCell, HilosDaemonNodeShell],
  template: `
    @let routes = this.routes();
    @let summary = this.summary();
    @let emptyState = this.emptyState();
    @let silent = this.silent();
    @let period = this.period();
    @let status = this.status();
    @let statusMessage = this.statusMessage();

    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <div class="hilos-stack mb-3">
          <p
            class="small text-body-secondary mb-0 invisible"
            aria-hidden="true"
          >
            {{ waitingMessage }}
          </p>
          <p
            class="small text-body-secondary mb-0 invisible"
            aria-hidden="true"
          >
            {{ silentMessage }}
          </p>
          <p
            class="small text-body-secondary mb-0 invisible"
            aria-hidden="true"
          >
            {{ lastReportReserve }}
          </p>
          @if (status !== null) {
            <p
              class="small text-body-secondary mb-0"
              data-id="hilos-daemon-http-status"
              [attr.data-status]="status"
            >
              {{ statusMessage }}
            </p>
          }
        </div>
        <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-globe" aria-hidden="true"></i
                ><span class="small">Address</span>
              </div>
              <div
                class="fs-6 font-monospace lh-1 mb-1 text-break"
                data-id="hilos-daemon-http-address"
              >
                {{ summary === null ? '—' : formatDaemonHttpAddress(summary) }}
              </div>
              <div class="small text-body-secondary">
                {{
                  summary === null ? '—' : silent ? 'Last report' : 'Listening'
                }}
              </div>
            </div>
          </div>
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-arrow-down-up" aria-hidden="true"></i
                ><span class="small">Requests</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1 text-break"
                data-id="hilos-daemon-http-requests"
              >
                {{
                  summary === null
                    ? '—'
                    : formatDaemonHttpCount(summary.requests)
                }}
              </div>
              <div
                class="small text-body-secondary"
                data-id="hilos-daemon-http-period"
              >
                {{ period }}
              </div>
            </div>
          </div>
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i
                ><span class="small">Errors</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1 text-break"
                data-id="hilos-daemon-http-errors"
              >
                {{
                  summary === null
                    ? '—'
                    : formatDaemonHttpCount(summary.serverErrors)
                }}
              </div>
              <div class="small text-body-secondary">
                {{ '5xx · ' + period }}
              </div>
            </div>
          </div>
          <div class="col">
            <div class="border rounded-3 p-3 h-100">
              <div
                class="d-flex align-items-center gap-2 text-body-secondary mb-1"
              >
                <i class="bi bi-hourglass-split" aria-hidden="true"></i
                ><span class="small">Slow</span>
              </div>
              <div
                class="fs-5 lh-1 mb-1 text-break"
                data-id="hilos-daemon-http-slow"
              >
                {{
                  summary === null ? '—' : formatDaemonHttpCount(summary.slow)
                }}
              </div>
              <div class="small text-body-secondary">
                {{ 'Over 1 s · ' + period }}
              </div>
            </div>
          </div>
        </div>
        <hilos-viewport-table [controller]="routes.controller">
          <ng-template [hilosTableCell]="DAEMON_HTTP_ROUTE_PATH_FIELD" let-row>
            <code>{{ formatDaemonHttpRoute(row) }}</code>
            @if (row.agentType !== null) {
              <span class="small text-body-secondary ms-1">agent</span>
              <span
                class="badge bg-body-tertiary text-body border font-monospace ms-1"
                >{{ row.agentType }}</span
              >
            }
          </ng-template>
          <ng-template
            [hilosTableCell]="DAEMON_HTTP_ROUTE_REQUESTS_FIELD"
            let-row
          >
            <span [ngClass]="{ 'text-body-secondary': row.requests === 0 }">{{
              formatDaemonHttpCount(row.requests)
            }}</span>
          </ng-template>
          <ng-template
            [hilosTableCell]="DAEMON_HTTP_ROUTE_ERRORS_FIELD"
            let-row
          >
            <span
              [ngClass]="{ 'text-body-secondary': row.serverErrors === 0 }"
              >{{ formatDaemonHttpCount(row.serverErrors) }}</span
            >
          </ng-template>
          <ng-template
            [hilosTableCell]="DAEMON_HTTP_ROUTE_SLOWEST_FIELD"
            let-row
          >
            <span
              class="small"
              [ngClass]="{ 'text-body-secondary': row.slowestMs === null }"
              >{{ formatDaemonHttpSlowest(row) }}</span
            >
          </ng-template>
          <ng-template #empty>
            @if (emptyState === 'waiting') {
              <div>{{ waitingMessage }}</div>
            } @else if (emptyState === 'silent') {
              <div>{{ silentMessage }}</div>
            } @else {
              <div>Loading…</div>
            }
          </ng-template>
        </hilos-viewport-table>
      </ng-template>
    </hilos-daemon-node-shell>
  `,
})
export class HilosDaemonHttpServerPage {
  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly nodeId = computed(
    () => this.route().params['nodeId'] ?? '',
  )
  protected readonly now = Date.now

  readonly context = input.required<HilosDaemonHttpContext>()
  protected readonly page = HilosPages.DAEMON_HTTP_SERVER
  protected readonly heading = signal<HilosDaemonNodeHeading | null>(null)
  protected readonly summary = signal<HilosDaemonHttpSummary | null>(null)
  protected readonly routes = computed(() =>
    createHilosDaemonHttpRoutesTable(this.context(), this.nodeId()),
  )
  protected readonly emptyState = computed(() =>
    daemonHttpEmptyState(this.heading(), this.summary()),
  )
  protected readonly silent = computed(
    () => this.heading()?.state === DAEMON_NODE_STATE_SILENT,
  )
  protected readonly silentSince = computed(() =>
    formatDaemonNodeSilentSince(this.heading(), Date.now()),
  )
  protected readonly period = computed(() => {
    const summary = this.summary()
    return summary === null ? '—' : formatDaemonHttpPeriod(summary)
  })
  protected readonly status = computed(() =>
    this.emptyState() !== 'none'
      ? this.emptyState()
      : this.silent()
        ? 'last-report'
        : null,
  )
  protected readonly waitingMessage =
    'The node has not reported HTTP metrics yet'
  protected readonly silentMessage =
    'The node is silent and has not reported HTTP metrics'
  protected readonly lastReportReserve =
    'This node has been silent since September 30, 2026, 11:59 PM. The values below are from its last report.'
  protected readonly statusMessage = computed(() =>
    this.status() === 'waiting'
      ? this.waitingMessage
      : this.status() === 'silent'
        ? this.silentMessage
        : this.status() === 'last-report'
          ? `This node has been silent since ${this.silentSince() ?? '—'}. The values below are from its last report.`
          : '',
  )
  constructor() {
    effect((onCleanup) => {
      const routes = this.routes()
      const scopes = this.context().scopes
      const stops = [
        mirrorHilosSignal(readHilosDaemonNodeHeading(scopes), this.heading),
        mirrorHilosSignal(readHilosDaemonHttpSummary(scopes), this.summary),
      ]
      routes.start()
      onCleanup(() => {
        stops.forEach((stop) => stop())
        routes.dispose()
      })
    })
  }
  protected readonly DAEMON_HTTP_ROUTE_PATH_FIELD = DAEMON_HTTP_ROUTE_PATH_FIELD
  protected readonly DAEMON_HTTP_ROUTE_REQUESTS_FIELD =
    DAEMON_HTTP_ROUTE_REQUESTS_FIELD
  protected readonly DAEMON_HTTP_ROUTE_ERRORS_FIELD =
    DAEMON_HTTP_ROUTE_ERRORS_FIELD
  protected readonly DAEMON_HTTP_ROUTE_SLOWEST_FIELD =
    DAEMON_HTTP_ROUTE_SLOWEST_FIELD
  protected readonly formatDaemonHttpAddress = formatDaemonHttpAddress
  protected readonly formatDaemonHttpCount = formatDaemonHttpCount
  protected readonly formatDaemonHttpRoute = formatDaemonHttpRoute
  protected readonly formatDaemonHttpSlowest = formatDaemonHttpSlowest
}
