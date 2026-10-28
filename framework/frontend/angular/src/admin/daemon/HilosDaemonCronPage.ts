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
  createHilosDaemonCronTable,
  daemonCronEmptyState,
  daemonCronHoldsIdleNotice,
  daemonCronIdleNotice,
  formatDaemonNodeSilentSince,
  readDaemonCronReported,
  readHilosDaemonNodeHeading,
  DAEMON_CRON_NAME_FIELD,
  DAEMON_CRON_AGENT_FIELD,
  DAEMON_CRON_EXPRESSION_FIELD,
  DAEMON_CRON_LAST_RUN_FIELD,
  DAEMON_CRON_NEXT_RUN_FIELD,
  formatDaemonCronExecutor,
  formatDaemonCronLastRun,
  formatDaemonCronNextRun,
  type HilosDaemonCronContext,
  type HilosDaemonNodeHeading,
  type HilosDaemonCronRow,
} from '@hilos/core/admin/daemon'
import {
  HilosPages,
  hilosTimeZone,
  type TableViewportRow,
} from '@hilos/core/base'

/** Angular view of the Daemon CronPage. */
@Component({
  selector: 'hilos-daemon-cron-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgClass, HilosViewportTable, HilosTableCell, HilosDaemonNodeShell],
  template: `
    @let cron = this.cron();
    @let rows = this.rows();
    @let emptyState = this.emptyState();
    @let notice = this.notice();
    @let holdsNotice = this.holdsNotice();
    @let silentSince = this.silentSince();
    @let clustered = this.clustered();
    @let runTimes = this.runTimes();

    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <div class="hilos-stack">
          <p class="small text-body-secondary invisible" aria-hidden="true">
            This node has been silent since September 30, 2026, 11:59 PM. The
            rules below are from its last report.
          </p>
          @if (silentSince !== null && rows.length > 0) {
            <p
              class="small text-body-secondary"
              data-id="hilos-daemon-cron-silent"
            >
              This node has been silent since {{ silentSince }}. The rules below
              are from its last report.
            </p>
          }
        </div>
        @if (holdsNotice) {
          <div class="hilos-stack">
            <div
              class="alert alert-warning d-flex align-items-start gap-3 py-2 small invisible"
              aria-hidden="true"
            >
              <i class="bi bi-pause-circle fs-5" aria-hidden="true"></i>
              <div>
                Daemon rules do not run on this node: only the leader runs them.
                They will start here if leadership moves to this node.
              </div>
            </div>
            @if (notice !== 'none') {
              <div
                class="alert alert-warning d-flex align-items-start gap-3 py-2 small"
                data-id="hilos-daemon-cron-not-leader"
                [attr.data-notice]="notice"
              >
                <i class="bi bi-pause-circle fs-5" aria-hidden="true"></i>
                <div>
                  Daemon rules do not run on this node: only the leader runs
                  them.
                  @if (notice === 'idle_until_leader') {
                    They will start here if leadership moves to this node.
                  }
                </div>
              </div>
            }
          </div>
        }
        <p
          class="small text-body-secondary mb-2"
          data-id="hilos-daemon-cron-time-zone"
        >
          <i class="bi bi-clock me-1" aria-hidden="true"></i>Expressions are
          evaluated in the project's time zone —
          <span
            class="font-monospace text-break"
            data-id="hilos-daemon-cron-project-time-zone"
            >{{ projectTimeZone() ?? '—' }}</span
          >. Run times are shown in your time zone.
        </p>
        <hilos-viewport-table [controller]="cron.controller">
          <ng-template [hilosTableCell]="DAEMON_CRON_NAME_FIELD" let-row>
            <code class="fw-semibold text-break">{{ row.name }}</code>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_CRON_AGENT_FIELD" let-row>
            @if (row.agentId === null) {
              <span
                class="badge bg-body-tertiary text-body border text-wrap text-break"
                [attr.data-id]="'hilos-daemon-cron-executor-' + row.rowKey"
                ><i class="bi bi-cpu me-1" aria-hidden="true"></i
                >{{ formatDaemonCronExecutor(row, clustered) }}</span
              >
            } @else {
              <span
                class="badge bg-body-tertiary text-body border font-monospace text-wrap text-break"
                [attr.data-id]="'hilos-daemon-cron-executor-' + row.rowKey"
                >{{ formatDaemonCronExecutor(row, clustered) }}</span
              >
            }
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_CRON_EXPRESSION_FIELD" let-row>
            <code>{{ row.expression }}</code>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_CRON_LAST_RUN_FIELD" let-row>
            <span
              class="small"
              [ngClass]="{
                'text-body-secondary':
                  row.idleReason !== null || row.lastRunAt === null,
              }"
              >{{ runTimes.get(row.rowKey)?.lastRun ?? '—' }}</span
            >
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_CRON_NEXT_RUN_FIELD" let-row>
            <span
              class="small"
              [ngClass]="{
                'text-body-secondary':
                  row.idleReason !== null || row.nextRunAt === null,
              }"
              >{{ runTimes.get(row.rowKey)?.nextRun ?? '—' }}</span
            >
          </ng-template>
          <ng-template #empty>
            @if (emptyState === 'waiting') {
              <div data-id="hilos-daemon-cron-empty-waiting">
                <div class="fw-semibold">
                  The node has not reported its schedule yet
                </div>
                <p class="mb-0">
                  Nothing has arrived from this node yet — not zero rules.
                </p>
              </div>
            } @else if (emptyState === 'silent') {
              <div data-id="hilos-daemon-cron-empty-silent">
                <div class="fw-semibold">
                  The node is silent and never reported its schedule
                </div>
                <p class="mb-0">Its rules will appear when it reports again.</p>
              </div>
            } @else {
              <div data-id="hilos-daemon-cron-empty-none">
                <div class="fw-semibold">The node reports no cron rules</div>
              </div>
            }
          </ng-template>
        </hilos-viewport-table>
      </ng-template>
    </hilos-daemon-node-shell>
  `,
})
export class HilosDaemonCronPage {
  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly nodeId = computed(
    () => this.route().params['nodeId'] ?? '',
  )

  readonly context = input.required<HilosDaemonCronContext>()
  protected readonly page = HilosPages.DAEMON_CRON
  protected readonly projectTimeZone = signal<string | undefined>(undefined)
  protected readonly heading = signal<HilosDaemonNodeHeading | null>(null)
  protected readonly reported = signal(false)
  protected readonly cron = computed(() =>
    createHilosDaemonCronTable(this.context(), this.nodeId()),
  )
  protected readonly rows = signal<
    readonly TableViewportRow<HilosDaemonCronRow>[]
  >([])
  private readonly rules = computed(() =>
    this.rows().flatMap(({ row }) => (row === null ? [] : [row])),
  )
  protected readonly emptyState = computed(() =>
    daemonCronEmptyState(this.heading(), this.reported()),
  )
  protected readonly notice = computed(() =>
    daemonCronIdleNotice(this.rules(), this.heading()),
  )
  protected readonly holdsNotice = computed(() =>
    daemonCronHoldsIdleNotice(this.rules(), this.heading()),
  )
  protected readonly silentSince = computed(() => {
    void this.timeZone()
    return formatDaemonNodeSilentSince(this.heading(), Date.now())
  })
  protected readonly runTimes = computed(() => {
    void this.timeZone()
    const now = Date.now()
    return new Map(
      this.rules().map(
        (row) =>
          [
            row.rowKey,
            {
              lastRun: formatDaemonCronLastRun(row, now),
              nextRun: formatDaemonCronNextRun(row, now),
            },
          ] as const,
      ),
    )
  })
  protected readonly clustered = computed(
    () => this.heading()?.clustered === true,
  )
  constructor() {
    effect((onCleanup) => {
      const connection = this.context().connection
      const updateProjectTimeZone = (): void =>
        this.projectTimeZone.set(connection.projectTimeZone)
      updateProjectTimeZone()
      const stopHandshake = connection.on('handshake', updateProjectTimeZone)
      onCleanup(stopHandshake)
    })
    effect((onCleanup) => {
      const cron = this.cron()
      const scopes = this.context().scopes
      const stops = [
        mirrorHilosSignal(readHilosDaemonNodeHeading(scopes), this.heading),
        mirrorHilosSignal(readDaemonCronReported(scopes), this.reported),
        mirrorHilosSignal(cron.controller.rows, this.rows),
      ]
      cron.start()
      onCleanup(() => {
        stops.forEach((stop) => stop())
        cron.dispose()
      })
    })
  }
  protected readonly DAEMON_CRON_NAME_FIELD = DAEMON_CRON_NAME_FIELD
  protected readonly DAEMON_CRON_AGENT_FIELD = DAEMON_CRON_AGENT_FIELD
  protected readonly DAEMON_CRON_EXPRESSION_FIELD = DAEMON_CRON_EXPRESSION_FIELD
  protected readonly DAEMON_CRON_LAST_RUN_FIELD = DAEMON_CRON_LAST_RUN_FIELD
  protected readonly DAEMON_CRON_NEXT_RUN_FIELD = DAEMON_CRON_NEXT_RUN_FIELD
  protected readonly formatDaemonCronExecutor = formatDaemonCronExecutor
}
