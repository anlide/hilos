import {
  DAEMON_WORKER_INDEX_FIELD,
  DAEMON_WORKER_KIND_FIELD,
  DAEMON_WORKER_PID_FIELD,
  DAEMON_WORKER_AGENT_COUNT_FIELD,
  DAEMON_WORKER_MEMORY_FIELD,
  DAEMON_WORKER_AGENT_IDS_FIELD,
} from '@hilos/core/admin/daemon'
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
  createHilosDaemonWorkersTable,
  daemonProcessesEmptyState,
  daemonWorkerLogPath,
  formatDaemonNodeSilentSince,
  formatDaemonWorkerAgentCount,
  formatDaemonWorkerKind,
  formatDaemonWorkerMemory,
  formatDaemonWorkerName,
  formatDaemonWorkerPid,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonWorkersContext,
} from '@hilos/core/admin/daemon'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import {
  HilosLink,
  HilosViewportTable,
  HilosTableCell,
  HilosTableDetail,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'
import type { TableViewportRow } from '@hilos/core/base'
import type {
  HilosDaemonNodeHeading,
  HilosDaemonWorkerRow,
} from '@hilos/core/admin/daemon'

/** Angular view of the Daemon WorkersPage. */
@Component({
  selector: 'hilos-daemon-workers-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgClass,
    HilosLink,
    HilosViewportTable,
    HilosTableCell,
    HilosTableDetail,
    HilosDaemonNodeShell,
  ],
  template: `
    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <div class="position-relative">
          <p class="small text-body-secondary invisible" aria-hidden="true">
            This node has been silent since September 30, 2026, 11:59 PM. The
            workers below are from its last report.
          </p>
          @if (silentSince() !== null && rows().length > 0) {
            <p
              class="small text-body-secondary position-absolute top-0 start-0 w-100"
              data-id="hilos-daemon-workers-silent"
            >
              This node has been silent since {{ silentSince() }}. The workers
              below are from its last report.
            </p>
          }
        </div>
        <hilos-viewport-table [controller]="workers().controller">
          <ng-template [hilosTableCell]="DAEMON_WORKER_INDEX_FIELD" let-row>
            <code class="fw-semibold">{{
              formatDaemonWorkerName(row.index, row.kind)
            }}</code>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_WORKER_KIND_FIELD" let-row>
            <span class="small">{{ formatDaemonWorkerKind(row) }}</span>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_WORKER_PID_FIELD" let-row
            ><code>{{ formatDaemonWorkerPid(row) }}</code></ng-template
          >
          <ng-template
            [hilosTableCell]="DAEMON_WORKER_AGENT_COUNT_FIELD"
            let-row
          >
            <span
              class="d-block text-center"
              [ngClass]="{ 'text-body-secondary': row.agentCount === 0 }"
            >
              {{ formatDaemonWorkerAgentCount(row) }}
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_WORKER_MEMORY_FIELD" let-row>{{
            formatDaemonWorkerMemory(row)
          }}</ng-template>
          <ng-template hilosTableCell="actions" let-row>
            <a
              [hilosLink]="daemonWorkerLogPath(nodeId(), row)"
              class="btn btn-sm btn-outline-secondary text-nowrap"
              [attr.data-id]="'hilos-daemon-worker-log-' + row.rowKey"
              >Log</a
            >
          </ng-template>
          <ng-template
            [hilosTableDetail]="DAEMON_WORKER_AGENT_IDS_FIELD"
            let-row
          >
            <div
              class="d-flex flex-wrap gap-1"
              [attr.data-id]="'hilos-daemon-worker-agents-' + row.rowKey"
            >
              @for (id of row.agentIds; track id) {
                <span
                  class="badge bg-body-tertiary text-body border font-monospace"
                  >{{ id }}</span
                >
              }
              @if (row.agentIds.length === 0) {
                <span class="text-body-secondary">—</span>
              }
            </div>
          </ng-template>
          <ng-template #empty>
            @if (emptyState() === 'waiting') {
              <div data-id="hilos-daemon-workers-empty-waiting">
                <div class="fw-semibold">
                  The node has not reported its workers yet
                </div>
                <p class="mb-0">
                  Nothing has arrived from this node yet — not zero workers.
                </p>
              </div>
            } @else if (emptyState() === 'silent') {
              <div data-id="hilos-daemon-workers-empty-silent">
                <div class="fw-semibold">
                  The node is silent and never reported its workers
                </div>
                <p class="mb-0">
                  Its workers will appear when it reports again.
                </p>
              </div>
            } @else {
              <div data-id="hilos-daemon-workers-empty-none">
                <div class="fw-semibold">The node reports no workers</div>
              </div>
            }
          </ng-template>
        </hilos-viewport-table>
      </ng-template>
    </hilos-daemon-node-shell>
  `,
})
export class HilosDaemonWorkersPage {
  protected readonly DAEMON_WORKER_AGENT_IDS_FIELD =
    DAEMON_WORKER_AGENT_IDS_FIELD
  protected readonly DAEMON_WORKER_MEMORY_FIELD = DAEMON_WORKER_MEMORY_FIELD
  protected readonly DAEMON_WORKER_AGENT_COUNT_FIELD =
    DAEMON_WORKER_AGENT_COUNT_FIELD
  protected readonly DAEMON_WORKER_PID_FIELD = DAEMON_WORKER_PID_FIELD
  protected readonly DAEMON_WORKER_KIND_FIELD = DAEMON_WORKER_KIND_FIELD
  protected readonly DAEMON_WORKER_INDEX_FIELD = DAEMON_WORKER_INDEX_FIELD
  protected readonly daemonWorkerLogPath = daemonWorkerLogPath
  protected readonly formatDaemonWorkerAgentCount = formatDaemonWorkerAgentCount
  protected readonly formatDaemonWorkerKind = formatDaemonWorkerKind
  protected readonly formatDaemonWorkerMemory = formatDaemonWorkerMemory
  protected readonly formatDaemonWorkerName = formatDaemonWorkerName
  protected readonly formatDaemonWorkerPid = formatDaemonWorkerPid
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosDaemonWorkersContext>()
  protected readonly page = HilosPages.DAEMON_WORKERS

  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly nodeId = computed(
    () => this.route().params['nodeId'] ?? '',
  )
  protected readonly heading = signal<HilosDaemonNodeHeading | null>(null)
  protected readonly reported = signal(false)
  protected readonly emptyState = computed(() =>
    daemonProcessesEmptyState(this.heading(), this.reported()),
  )
  protected readonly silentSince = computed(() => {
    this.timeZone()
    return formatDaemonNodeSilentSince(this.heading(), Date.now())
  })

  protected readonly workers = computed(() =>
    createHilosDaemonWorkersTable(this.context(), this.nodeId()),
  )
  protected readonly rows = signal<
    readonly TableViewportRow<HilosDaemonWorkerRow>[]
  >([])
  constructor() {
    effect((onCleanup) => {
      const workers = this.workers()
      const scopes = this.context().scopes
      const stops = [
        mirrorHilosSignal(readHilosDaemonNodeHeading(scopes), this.heading),
        mirrorHilosSignal(readDaemonProcessesReported(scopes), this.reported),
        mirrorHilosSignal(workers.controller.rows, this.rows),
      ]
      workers.start()
      onCleanup(() => {
        stops.forEach((stop) => stop())
        workers.dispose()
      })
    })
  }
}
