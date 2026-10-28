import {
  DAEMON_AGENT_ID_FIELD,
  DAEMON_AGENT_WORKER_INDEX_FIELD,
  DAEMON_AGENT_PLACEMENT_FIELD,
  DAEMON_AGENT_OWNS_RT_FIELD,
  DAEMON_AGENT_OWNS_DB_FIELD,
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
  createHilosDaemonAgentRestart,
  createHilosDaemonAgentsTable,
  DAEMON_AGENT_PLACEMENT_POLICY,
  daemonAgentLogPath,
  daemonProcessesEmptyState,
  formatDaemonAgentKind,
  formatDaemonAgentOwnership,
  formatDaemonAgentWorker,
  formatDaemonNodeSilentSince,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonAgentRow,
  type HilosDaemonAgentsContext,
} from '@hilos/core/admin/daemon'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import {
  HilosLink,
  HilosViewportTable,
  HilosTableCell,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
  HilosModal,
  HilosActionError,
  LoadingButton,
  createHilosTrackedAction,
} from '@hilos/angular/base'
import { HilosDaemonNodeShell } from './HilosDaemonNodeShell.js'
import type { TableViewportRow, HilosTableBody } from '@hilos/core/base'
import type { HilosDaemonNodeHeading } from '@hilos/core/admin/daemon'

/** Angular view of the Daemon AgentsPage. */
@Component({
  selector: 'hilos-daemon-agents-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgClass,
    LoadingButton,
    HilosLink,
    HilosViewportTable,
    HilosTableCell,
    HilosDaemonNodeShell,
    HilosModal,
    HilosActionError,
  ],
  template: `
    <hilos-daemon-node-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        <div class="position-relative">
          <p class="small text-body-secondary invisible" aria-hidden="true">
            This node has been silent since September 30, 2026, 11:59 PM. The
            agents below are from its last report.
          </p>
          @if (silentSince() !== null && rows().length > 0) {
            <p
              class="small text-body-secondary position-absolute top-0 start-0 w-100"
              data-id="hilos-daemon-agents-silent"
            >
              This node has been silent since {{ silentSince() }}. The agents
              below are from its last report.
            </p>
          }
        </div>
        <hilos-viewport-table [controller]="agents().controller">
          <ng-template [hilosTableCell]="DAEMON_AGENT_ID_FIELD" let-row>
            <code class="fw-semibold">{{ row.agentId }}</code>
            @if (row.restarting) {
              <span
                class="badge text-bg-warning ms-2"
                [attr.data-id]="'hilos-daemon-agent-restarting-' + row.rowKey"
                >Restarting</span
              >
            }
          </ng-template>
          <ng-template
            [hilosTableCell]="DAEMON_AGENT_WORKER_INDEX_FIELD"
            let-row
          >
            <code>{{ formatDaemonAgentWorker(row) }}</code>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_AGENT_PLACEMENT_FIELD" let-row>
            <span class="small">{{
              formatDaemonAgentKind(row, heading()?.clustered === true)
            }}</span>
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_AGENT_OWNS_RT_FIELD" let-row>
            <code
              class="small"
              [ngClass]="{ 'text-body-secondary': row.ownsRt.length === 0 }"
              >{{ formatDaemonAgentOwnership(row.ownsRt) }}</code
            >
          </ng-template>
          <ng-template [hilosTableCell]="DAEMON_AGENT_OWNS_DB_FIELD" let-row>
            <code
              class="small"
              [ngClass]="{ 'text-body-secondary': row.ownsDb.length === 0 }"
              >{{ formatDaemonAgentOwnership(row.ownsDb) }}</code
            >
          </ng-template>
          <ng-template hilosTableCell="actions" let-row>
            <a
              [hilosLink]="daemonAgentLogPath(nodeId(), row)"
              class="small me-2"
              [attr.data-id]="'hilos-daemon-agent-log-' + row.rowKey"
              >Log</a
            >
            <button
              hilosLoadingButton
              class="btn-sm btn-outline-secondary"
              [disabled]="
                restartBusy() ||
                row.restarting ||
                heading() === null ||
                heading()?.state === 'silent' ||
                tableBody() === 'loading' ||
                frozenSince() !== null
              "
              [attr.data-id]="'hilos-daemon-agent-restart-' + row.rowKey"
              (click)="openRestart(row)"
            >
              Restart
            </button>
          </ng-template>
          <ng-template #empty>
            @if (emptyState() === 'waiting') {
              <div data-id="hilos-daemon-agents-empty-waiting">
                <div class="fw-semibold">
                  The node has not reported its agents yet
                </div>
                <p class="mb-0">
                  Nothing has arrived from this node yet — not zero agents.
                </p>
              </div>
            } @else if (emptyState() === 'silent') {
              <div data-id="hilos-daemon-agents-empty-silent">
                <div class="fw-semibold">
                  The node is silent and never reported its agents
                </div>
                <p class="mb-0">
                  Its agents will appear when it reports again.
                </p>
              </div>
            } @else {
              <div data-id="hilos-daemon-agents-empty-none">
                <div class="fw-semibold">The node reports no agents</div>
              </div>
            }
          </ng-template>
        </hilos-viewport-table>
        <hilos-modal
          [open]="restartOpen()"
          title="Restart agent"
          [busy]="restartBusy()"
          initialFocus="dialog"
          (openChange)="closeRestart()"
        >
          <hilos-action-error
            [action]="restartAction"
            detailsTitle="Couldn't restart the agent"
          ></hilos-action-error>
          <p>
            Agent
            <code data-id="hilos-daemon-restart-agent">{{
              restartIdentity()?.agentId
            }}</code>
            on node
            <code data-id="hilos-daemon-restart-node">{{ nodeId() }}</code>
            will be stopped and will immediately start again under its placement
            rules.
          </p>
          <p class="text-body-secondary">
            Neighboring agents on the same worker are unaffected.
          </p>
          @if (policyWarning()) {
            <p
              class="alert alert-warning"
              data-id="hilos-daemon-restart-policy"
            >
              This policy-placed agent may come back on a different node.
            </p>
          }
          <div class="border rounded p-2 bg-body-tertiary">
            <div class="small text-body-secondary mb-1">
              The same from a terminal:
            </div>
            <code class="text-break" data-id="hilos-daemon-restart-cli">{{
              restartCommand()
            }}</code>
          </div>
          <p
            class="small text-body-secondary mt-2 mb-0"
            [ngClass]="{ invisible: !restartUnavailable() }"
            [attr.aria-hidden]="!restartUnavailable()"
            data-id="hilos-daemon-restart-unavailable"
          >
            This agent has disappeared or is currently unavailable for restart.
          </p>
          <ng-template #modalActions let-requestClose="requestClose">
            <button
              type="button"
              class="btn btn-secondary"
              [disabled]="restartBusy()"
              data-id="hilos-daemon-restart-cancel"
              (click)="requestClose()"
            >
              Cancel
            </button>
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="restartBusy()"
              [disabled]="restartUnavailable()"
              data-id="hilos-daemon-restart-confirm"
              (click)="submitRestart()"
            >
              Restart
            </button>
          </ng-template>
        </hilos-modal>
      </ng-template>
    </hilos-daemon-node-shell>
  `,
})
export class HilosDaemonAgentsPage {
  protected readonly DAEMON_AGENT_OWNS_DB_FIELD = DAEMON_AGENT_OWNS_DB_FIELD
  protected readonly DAEMON_AGENT_OWNS_RT_FIELD = DAEMON_AGENT_OWNS_RT_FIELD
  protected readonly DAEMON_AGENT_PLACEMENT_FIELD = DAEMON_AGENT_PLACEMENT_FIELD
  protected readonly DAEMON_AGENT_WORKER_INDEX_FIELD =
    DAEMON_AGENT_WORKER_INDEX_FIELD
  protected readonly DAEMON_AGENT_ID_FIELD = DAEMON_AGENT_ID_FIELD
  protected readonly daemonAgentLogPath = daemonAgentLogPath
  protected readonly formatDaemonAgentKind = formatDaemonAgentKind
  protected readonly formatDaemonAgentOwnership = formatDaemonAgentOwnership
  protected readonly formatDaemonAgentWorker = formatDaemonAgentWorker
  /** The project's connection, scope stores and action lifecycle. */
  readonly context = input.required<HilosDaemonAgentsContext>()
  protected readonly page = HilosPages.DAEMON_AGENTS

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

  protected readonly agents = computed(() =>
    createHilosDaemonAgentsTable(this.context(), this.nodeId()),
  )
  protected readonly restart = computed(() =>
    createHilosDaemonAgentRestart(
      this.context(),
      this.agents().controller,
      this.nodeId(),
    ),
  )
  protected readonly rows = signal<
    readonly TableViewportRow<HilosDaemonAgentRow>[]
  >([])
  protected readonly tableBody = signal<HilosTableBody>('loading')
  protected readonly frozenSince = signal<number | null>(null)
  protected readonly restartOpen = signal(false)
  protected readonly restartTarget = signal<HilosDaemonAgentRow | undefined>(
    undefined,
  )
  protected readonly canRestart = signal(false)
  protected readonly restartIdentity = signal<HilosDaemonAgentRow | null>(null)
  protected readonly restartAction = createHilosTrackedAction()
  protected readonly restartBusy = this.restartAction.busy
  protected readonly restartUnavailable = computed(
    () =>
      this.restartTarget() === undefined ||
      this.restartTarget()?.restarting === true ||
      this.heading() === null ||
      this.heading()?.state === 'silent' ||
      this.tableBody() === 'loading' ||
      this.tableBody() === 'unavailable' ||
      this.frozenSince() !== null,
  )
  protected readonly policyWarning = computed(
    () =>
      this.heading()?.clustered === true &&
      this.restartTarget()?.placement === DAEMON_AGENT_PLACEMENT_POLICY,
  )
  protected readonly restartCommand = computed(
    () =>
      `php cli.php agent:restart ${this.restartIdentity()?.agentId ?? ''}` +
      (this.heading()?.clustered === true ? ` --node=${this.nodeId()}` : ''),
  )
  constructor() {
    effect((onCleanup) => {
      const agents = this.agents()
      const restart = this.restart()
      const scopes = this.context().scopes
      const stops = [
        mirrorHilosSignal(readHilosDaemonNodeHeading(scopes), this.heading),
        mirrorHilosSignal(readDaemonProcessesReported(scopes), this.reported),
        mirrorHilosSignal(agents.controller.rows, this.rows),
        mirrorHilosSignal(agents.controller.frame.body, this.tableBody),
        mirrorHilosSignal(agents.controller.frozenSince, this.frozenSince),
        mirrorHilosSignal(restart.opened, this.restartOpen),
        mirrorHilosSignal(restart.target, this.restartTarget),
        mirrorHilosSignal(restart.canSubmit, this.canRestart),
      ]
      this.restartIdentity.set(null)
      this.restartAction.clearError()
      agents.start()
      onCleanup(() => {
        stops.forEach((stop) => stop())
        restart.close()
        agents.dispose()
      })
    })
  }
  protected openRestart(row: HilosDaemonAgentRow): void {
    if (
      this.restartBusy() ||
      row.restarting ||
      this.heading() === null ||
      this.heading()?.state === 'silent' ||
      this.tableBody() === 'loading' ||
      this.tableBody() === 'unavailable' ||
      this.frozenSince() !== null
    )
      return
    const restart = this.restart()
    restart.open(row.rowKey)
    if (restart.opened.get()) {
      this.restartIdentity.set(restart.target.get() ?? null)
      this.restartAction.clearError()
    }
  }
  protected closeRestart(): void {
    if (!this.restartBusy()) this.restart().close()
  }
  protected async submitRestart(): Promise<void> {
    if (!this.canRestart() || this.restartBusy()) return
    const restart = this.restart()
    if (await this.restartAction.run(restart.sendRestart())) restart.close()
  }
}
