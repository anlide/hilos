import { NgClass } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  daemonCoresBoundHint,
  daemonEnvironmentAttention,
  daemonEnvironmentDifferences,
  daemonMemoryBoundHint,
  daemonMonopolisticCount,
  daemonNodeChildParams,
  daemonNodeLogPath,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonUptime,
  HilosPages,
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
} from '@hilos/core'
import { HilosLink } from '../../HilosLink.js'
import { HilosLongText } from '../../HilosLongText.js'
import { HilosModal } from '../../HilosModal.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** Angular view of the Daemon NodeInspector. */
@Component({
  selector: 'hilos-daemon-node-inspector',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgClass, HilosLink, HilosLongText, HilosModal],
  template: `
    @let current = node();

    <section
      class="border border-primary-subtle rounded p-3"
      data-id="hilos-daemon-inspector"
      [attr.data-node-id]="current.nodeId"
    >
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <i class="bi bi-hdd-network" aria-hidden="true"></i>
        <h2 class="h6 font-monospace text-break mb-0">{{ current.nodeId }}</h2>
        @if (overview().clustered) {
          <span
            class="badge border"
            [ngClass]="
              current.state === 'silent'
                ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
                : 'bg-body-tertiary text-body'
            "
            data-id="hilos-daemon-inspector-state"
            >{{
              current.state === null
                ? '—'
                : formatDaemonNodeState(current.state)
            }}</span
          >
          <span class="badge bg-body-tertiary text-body border"
            >Role: {{ current.role ?? '—' }}</span
          >
        } @else {
          <span class="badge bg-body-tertiary text-body border"
            >Single installation</span
          >
        }
        @if (logPath(); as logPathValue) {
          <a
            [hilosLink]="logPathValue"
            class="small ms-auto"
            data-id="hilos-daemon-node-log"
            >Node log <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i
          ></a>
        }
      </div>

      <div class="hilos-stack mb-3 small text-body-secondary">
        <div
          class="invisible d-flex align-items-center gap-2"
          aria-hidden="true"
          inert
        >
          <span class="flex-grow-1 text-truncate">&nbsp;</span>
          <span class="btn btn-link btn-sm p-0 flex-shrink-0"
            ><i class="bi bi-info-circle" aria-hidden="true"></i
          ></span>
        </div>
        <div
          class="d-flex align-items-center gap-2"
          [ngClass]="{ invisible: reportNotice() === null }"
          [attr.aria-hidden]="reportNotice() === null ? true : undefined"
          [attr.inert]="reportNotice() === null ? '' : null"
          data-id="hilos-daemon-report-notice"
        >
          <span class="flex-grow-1 text-truncate">{{
            reportNotice() ?? ''
          }}</span>
          <button
            type="button"
            class="btn btn-link btn-sm p-0 flex-shrink-0"
            aria-label="Show report details"
            data-id="hilos-daemon-report-details"
            (click)="noticeOpen.set(true)"
          >
            <i class="bi bi-info-circle" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <h3 class="h6">Processor</h3>
      <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Node cores</dt>
            <dd class="fs-5 mb-1" data-id="hilos-daemon-cores">
              {{ formatDaemonCores(measurements()?.cores) }}
            </dd>
            <dd
              class="small mb-0"
              [title]="daemonCoresBoundHint(measurements()?.coresBound)"
            >
              {{ daemonCoresBoundHint(measurements()?.coresBound) }}
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Daemon CPU</dt>
            <dd class="fs-5 mb-1" data-id="hilos-daemon-daemon-cores">
              {{ formatDaemonCores(measurements()?.daemonCores) }}
              <span class="small">cores</span>
            </dd>
            <dd class="small mb-0" data-id="hilos-daemon-cpu-percent">
              {{ formatDaemonPercentage(cpuPercent()) }} of available CPU
            </dd>
            <dd class="mb-0">
              <div
                class="progress hilos-progress-track mt-2"
                aria-hidden="true"
              >
                <div
                  class="progress-bar hilos-progress"
                  [style.--hilos-progress]="daemonProgressWidth(cpuPercent())"
                ></div>
              </div>
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Master CPU</dt>
            <dd class="fs-5 mb-1">
              {{ formatDaemonCores(measurements()?.masterCores) }}
              <span class="small">cores</span>
            </dd>
            <dd class="small mb-0">Daemon process without workers</dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Machine CPU</dt>
            <dd class="fs-5 mb-1">
              {{ formatDaemonPercentage(measurements()?.machineLoad) }}
            </dd>
            <dd class="small mb-0">Whole machine, including other processes</dd>
          </dl>
        </div>
      </div>

      <h3 class="h6">Memory</h3>
      <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Master</dt>
            <dd class="fs-5 mb-1">
              {{ formatDaemonBytes(measurements()?.masterMemory) }}
            </dd>
            <dd class="small mb-0">Daemon process</dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Workers</dt>
            <dd class="fs-5 mb-1">
              {{ formatDaemonBytes(measurements()?.workersMemory) }}
            </dd>
            <dd class="small mb-0" data-id="hilos-daemon-workers-measured">
              {{ formatDaemonNumber(measurements()?.workersMeasured) }} of
              {{ formatDaemonNumber(current.workers?.length) }} worker processes
              measured
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Measured total</dt>
            <dd class="fs-5 mb-1" data-id="hilos-daemon-total-memory">
              {{ formatDaemonBytes(memory()) }}
            </dd>
            <dd class="small mb-0" data-id="hilos-daemon-memory-percent">
              {{ formatDaemonPercentage(memoryPercent()) }} of ceiling
            </dd>
            <dd class="mb-0">
              <div
                class="progress hilos-progress-track mt-2"
                aria-hidden="true"
              >
                <div
                  class="progress-bar hilos-progress"
                  [style.--hilos-progress]="
                    daemonProgressWidth(memoryPercent())
                  "
                ></div>
              </div>
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Ceiling</dt>
            <dd class="fs-5 mb-1">
              {{ formatDaemonBytes(measurements()?.memoryCeiling) }}
            </dd>
            <dd
              class="small mb-0"
              [title]="
                daemonMemoryBoundHint(measurements()?.memoryCeilingBound)
              "
            >
              {{ daemonMemoryBoundHint(measurements()?.memoryCeilingBound) }}
            </dd>
          </dl>
        </div>
      </div>
      <p class="small text-body-secondary">
        PHP memory limit per process:
        {{ formatDaemonBytes(measurements()?.phpMemoryLimit) }}
      </p>

      <h3 class="h6">Node</h3>
      <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Uptime</dt>
            <dd class="fs-5 mb-1" data-id="hilos-daemon-uptime">
              {{ formatDaemonUptime(current.uptimeMinutes) }}
            </dd>
            <dd class="small mb-0">At the last report</dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Monopolistic</dt>
            <dd class="fs-5 mb-1" data-id="hilos-daemon-monopolistic-count">
              {{ formatDaemonNumber(daemonMonopolisticCount(current)) }}
            </dd>
            <dd class="small mb-0">Pool grows on demand</dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">
              Worker replacements / 24 h
            </dt>
            <dd class="fs-5 mb-1">
              {{ formatDaemonNumber(current.workerRestarts24h) }}
            </dd>
            <dd class="small mb-0">After unexpected worker loss</dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">
              Sessions on node
            </dt>
            <dd class="fs-5 mb-1">
              {{
                current.role === 'slave'
                  ? '—'
                  : formatDaemonNumber(current.standing?.sessions)
              }}
            </dd>
            <dd class="small mb-0" data-id="hilos-daemon-inspector-connections">
              {{
                current.role === 'slave'
                  ? 'Does not accept browsers'
                  : formatDaemonNumber(current.standing?.connections) +
                    ' connections'
              }}
            </dd>
          </dl>
        </div>
      </div>

      <h3 class="h6">Environment</h3>
      <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">APP_ENV</dt>
            <dd class="small mb-0 mt-2">
              @if (envPath(); as envPathValue) {
                <a
                  [hilosLink]="envPathValue"
                  data-id="hilos-daemon-environment-link"
                  >On the environment page</a
                >
              } @else {
                <span data-id="hilos-daemon-environment-unbuilt"
                  >Environment page is not available yet</span
                >
              }
            </dd>
            <dd class="small text-body-secondary mb-0 mt-1">
              Application environment
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">Node ID</dt>
            <dd class="small font-monospace text-break mb-0 mt-2">
              {{ current.nodeId }}
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">
              Requires attention
            </dt>
            <dd class="fs-5 mb-1" data-id="hilos-daemon-environment-attention">
              {{ formatDaemonNumber(daemonEnvironmentAttention(current)) }}
            </dd>
            <dd class="small mb-0">
              Missing, example, disk drift and orphan flags
            </dd>
          </dl>
        </div>
        <div class="col">
          <dl class="bg-body-tertiary rounded p-3 h-100 mb-0">
            <dt class="small text-body-secondary fw-normal">
              Differs from neighbors
            </dt>
            <dd
              class="fs-5 mb-1"
              data-id="hilos-daemon-environment-differences"
            >
              {{
                formatDaemonNumber(
                  daemonEnvironmentDifferences(overview(), current)
                )
              }}
            </dd>
            <dd class="small mb-0">
              {{
                overview().clustered
                  ? 'Diverged values and differing sources'
                  : 'No other nodes to compare'
              }}
            </dd>
          </dl>
        </div>
      </div>
      @if (mismatchPath(); as mismatchPathValue) {
        <p class="small">
          <a
            [hilosLink]="mismatchPathValue"
            data-id="hilos-daemon-mismatch-link"
            >Environment differences</a
          >
        </p>
      }

      <h3 class="h6">Node maintenance</h3>
      <p
        id="hilos-daemon-maintenance-unavailable"
        class="small text-body-secondary"
      >
        Node maintenance is not available yet.
      </p>
      <div
        class="d-flex flex-column flex-md-row flex-wrap gap-2"
        data-id="hilos-daemon-maintenance"
      >
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          disabled
          aria-describedby="hilos-daemon-maintenance-unavailable"
        >
          Drain node
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-secondary"
          disabled
          aria-describedby="hilos-daemon-maintenance-unavailable"
        >
          Reload configuration
        </button>
        <button
          type="button"
          class="btn btn-sm btn-outline-danger"
          disabled
          aria-describedby="hilos-daemon-maintenance-unavailable"
        >
          Force disconnect node
        </button>
      </div>
    </section>
    <hilos-modal
      [(open)]="noticeOpen"
      title="Node report"
      initialFocus="dialog"
    >
      <hilos-long-text
        kind="prose"
        [text]="reportNotice() ?? ''"
        dataId="hilos-daemon-report-full"
      ></hilos-long-text>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          (click)="requestClose()"
        >
          Close
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosDaemonNodeInspector {
  protected readonly daemonCoresBoundHint = daemonCoresBoundHint
  protected readonly daemonEnvironmentAttention = daemonEnvironmentAttention
  protected readonly daemonEnvironmentDifferences = daemonEnvironmentDifferences
  protected readonly daemonMemoryBoundHint = daemonMemoryBoundHint
  protected readonly daemonMonopolisticCount = daemonMonopolisticCount
  protected readonly daemonProgressWidth = daemonProgressWidth
  protected readonly formatDaemonBytes = formatDaemonBytes
  protected readonly formatDaemonCores = formatDaemonCores
  protected readonly formatDaemonNodeState = formatDaemonNodeState
  protected readonly formatDaemonNumber = formatDaemonNumber
  protected readonly formatDaemonPercentage = formatDaemonPercentage
  protected readonly formatDaemonUptime = formatDaemonUptime
  /** The whole value-free node picture. */
  readonly overview = input.required<HilosDaemonOverviewData>()
  /** The locally selected node. */
  readonly node = input.required<HilosDaemonOverviewNode>()
  private readonly router = inject(HILOS_ROUTER)
  protected readonly measurements = computed(() => this.node().measurements)
  protected readonly cpuPercent = computed(() =>
    daemonPercentage(
      this.measurements()?.daemonCores,
      this.measurements()?.cores,
    ),
  )
  protected readonly memory = computed(() => daemonTotalMemory(this.node()))
  protected readonly memoryPercent = computed(() =>
    daemonPercentage(this.memory(), this.measurements()?.memoryCeiling),
  )
  protected readonly silentSince = computed(() =>
    formatDaemonNodeSilentSince(
      {
        clustered: this.overview().clustered,
        state: this.node().state,
        silentSince: this.node().silentSince,
      },
      Date.now(),
    ),
  )
  protected readonly envPath = computed(() =>
    this.router.resolvePath(
      HilosPages.DAEMON_ENV,
      daemonNodeChildParams(this.node().nodeId),
    ),
  )
  protected readonly mismatchPath = computed(() =>
    this.overview().clustered
      ? this.router.resolvePath(HilosPages.DAEMON_ENV_MISMATCH, {})
      : undefined,
  )
  protected readonly logPath = computed(() =>
    daemonNodeLogPath(this.router.resolvePath, this.node().nodeId),
  )
  protected readonly noticeOpen = signal(false)
  protected readonly reportNotice = computed(() =>
    this.node().state === 'silent'
      ? this.node().role === null
        ? 'Silent · this node has not reported yet.'
        : `Silent · values from the last report. Last arrival: ${this.silentSince() ?? '—'}.`
      : this.node().role === null
        ? 'This node has not reported yet.'
        : null,
  )
}
