import { NgClass } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  inject,
  signal,
} from '@angular/core'
import {
  createHilosDaemonOverview,
  daemonMonopolisticCount,
  daemonPercentage,
  daemonProgressWidth,
  daemonTotalMemory,
  daemonWorkerOccupancy,
  formatDaemonBytes,
  formatDaemonCores,
  formatDaemonDatabaseAddress,
  formatDaemonNodeState,
  formatDaemonNodeSilentSince,
  formatDaemonNumber,
  formatDaemonPercentage,
  formatDaemonWorkerName,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { HilosPages, hilosTimeZone } from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosLink,
  HILOS_ROUTER,
  HilosSkeleton,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import type {
  HilosDaemonOverviewData,
  HilosDaemonDatabaseData,
  HilosDaemonOverviewNode,
} from '@hilos/core/admin/daemon'

/** Angular view of the Daemon Page. */
@Component({
  selector: 'hilos-daemon-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgClass, HilosAdminPage, HilosSkeleton, HilosLink],
  template: `
    @let picture = overview();
    @let primary = database();

    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Daemon pages">
      <div data-id="hilos-daemon-nodes-view">
        @if (picture === null) {
          <div data-id="hilos-daemon-overview-waiting">
            <p class="text-body-secondary">Waiting for the node picture…</p>
            <hilos-skeleton></hilos-skeleton>
          </div>
        } @else {
          <div
            class="d-flex flex-wrap gap-3 small text-body-secondary mb-3"
            data-id="hilos-daemon-entry-points"
          >
            <span
              ><i class="bi bi-window me-1" aria-hidden="true"></i
              >Browsers</span
            >
            <span
              ><i class="bi bi-terminal me-1" aria-hidden="true"></i>CLI on
              <span
                class="font-monospace"
                data-id="hilos-daemon-entry-command-port"
                >{{
                  picture.entryPoints?.commandPort == null
                    ? '—'
                    : ':' + picture.entryPoints!.commandPort
                }}</span
              ></span
            >
            <span
              ><i class="bi bi-globe me-1" aria-hidden="true"></i>HTTP
              <span
                class="font-monospace"
                data-id="hilos-daemon-entry-http-port"
                >{{
                  picture.entryPoints?.httpPort == null
                    ? '—'
                    : ':' + picture.entryPoints!.httpPort
                }}</span
              >
              @if (metricsPath(); as path) {
                <a
                  [hilosLink]="path"
                  class="ms-1"
                  data-id="hilos-daemon-entry-metrics"
                  >metrics</a
                >
              }
            </span>
          </div>
          @if (picture.clustered) {
            <div
              class="d-flex flex-wrap align-items-center gap-2 mb-3 small"
              data-id="hilos-daemon-consensus"
            >
              <span
                class="badge border"
                [ngClass]="
                  picture.consensus === null
                    ? 'bg-body-tertiary text-body'
                    : picture.consensus.onlineMasters >=
                        picture.consensus.quorumSize
                      ? 'bg-success-subtle text-success-emphasis border-success-subtle'
                      : 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
                "
                data-id="hilos-daemon-quorum"
              >
                Quorum:
                {{
                  picture.consensus === null
                    ? '—'
                    : picture.consensus.onlineMasters +
                      ' of ' +
                      picture.consensus.masters
                }}
                @if (picture.consensus) {
                  ·
                  {{
                    picture.consensus.onlineMasters >=
                    picture.consensus.quorumSize
                      ? 'Available'
                      : 'Unavailable'
                  }}
                }
              </span>
              <span
                class="badge bg-body-tertiary text-body border"
                data-id="hilos-daemon-term"
                >Term: {{ formatDaemonNumber(picture.consensus?.term) }}</span
              >
              @if (picture.consensus) {
                <span class="text-body-secondary text-break"
                  >Report: {{ picture.consensus.nodeId }} · Majority:
                  {{ picture.consensus.quorumSize }}</span
                >
              }
            </div>
          }

          @if (picture.nodes.length === 0) {
            <p
              class="border rounded p-4 text-center text-body-secondary"
              data-id="hilos-daemon-overview-empty"
            >
              No nodes in the picture
            </p>
          } @else {
            <div class="row g-3 mb-3" data-id="hilos-daemon-nodes">
              @for (
                node of picture.nodes;
                track node.nodeId;
                let nodeIndex = $index
              ) {
                <div
                  class="col-12 col-md-6 col-xl-4 d-flex flex-column flex-md-row gap-2"
                >
                  @if (picture.clustered && nodeIndex > 0) {
                    <div
                      class="d-flex flex-column align-items-center justify-content-center small text-body-secondary"
                      [attr.data-id]="'hilos-daemon-link-' + node.nodeId"
                      [attr.data-silent]="
                        !node.online || !picture.nodes[nodeIndex - 1]?.online
                      "
                    >
                      <i
                        class="bi"
                        [ngClass]="
                          !node.online || !picture.nodes[nodeIndex - 1]?.online
                            ? 'bi-link-45deg text-danger-emphasis'
                            : 'bi-arrow-left-right'
                        "
                        aria-hidden="true"
                      ></i>
                      <span class="visually-hidden"
                        >{{ picture.nodes[nodeIndex - 1]?.nodeId }} ↔
                        {{ node.nodeId }} ·
                        {{
                          !node.online || !picture.nodes[nodeIndex - 1]?.online
                            ? 'Silent member'
                            : 'Membership link'
                        }}</span
                      >
                    </div>
                  }
                  <a
                    [hilosLink]="
                      router.resolvePath(pages.DAEMON_NODE, {
                        nodeId: node.nodeId,
                      })!
                    "
                    class="btn bg-body text-body border rounded shadow-sm w-100 p-3 text-start text-decoration-none"
                    [ngClass]="{ 'border-danger-subtle': !node.online }"
                    [attr.aria-label]="'Node ' + node.nodeId"
                    [attr.data-id]="'hilos-daemon-node-' + node.nodeId"
                    [attr.data-state]="node.state"
                  >
                    <span
                      class="d-flex flex-wrap align-items-center gap-2 mb-3"
                    >
                      <i class="bi bi-hdd-network" aria-hidden="true"></i>
                      <span class="fw-semibold font-monospace text-break">{{
                        node.nodeId
                      }}</span>
                      @if (picture.clustered) {
                        <span
                          class="badge border"
                          [ngClass]="
                            node.state === 'silent'
                              ? 'bg-danger-subtle text-danger-emphasis border-danger-subtle'
                              : 'bg-body-tertiary text-body'
                          "
                          >{{
                            node.state === null
                              ? '—'
                              : formatDaemonNodeState(node.state)
                          }}</span
                        >
                      } @else {
                        <span class="badge bg-body-tertiary text-body border"
                          >Single installation</span
                        >
                      }
                    </span>
                    @if (!node.online) {
                      <span
                        class="d-block small text-body-secondary"
                        [attr.data-id]="'hilos-daemon-silent-' + node.nodeId"
                      >
                        Silent since {{ formatSilentSince(node) ?? '—' }}
                      </span>
                    } @else {
                      <span
                        class="d-flex flex-wrap gap-1 mb-3"
                        [attr.data-id]="'hilos-daemon-workers-' + node.nodeId"
                      >
                        @if (node.workers === null) {
                          <span class="small text-body-secondary"
                            >Workers: —</span
                          >
                        } @else if (node.workers.length === 0) {
                          <span class="small text-body-secondary"
                            >No workers</span
                          >
                        }
                        @for (
                          worker of node.workers ?? [];
                          track worker.kind + ':' + worker.index
                        ) {
                          <span
                            class="badge border font-monospace"
                            [ngClass]="
                              daemonWorkerOccupancy(worker) === 'free'
                                ? 'bg-body-tertiary text-body border-secondary-subtle'
                                : 'bg-primary-subtle text-primary-emphasis border-primary-subtle'
                            "
                            [attr.data-id]="
                              'hilos-daemon-worker-' +
                              node.nodeId +
                              '-' +
                              worker.kind +
                              '-' +
                              worker.index
                            "
                            [attr.data-occupancy]="
                              daemonWorkerOccupancy(worker)
                            "
                            [title]="
                              (worker.kind === 'monopolistic'
                                ? 'Monopolistic'
                                : 'Ordinary') +
                              ' worker · ' +
                              daemonWorkerOccupancy(worker) +
                              ' · ' +
                              worker.agentCount +
                              ' agents'
                            "
                          >
                            {{
                              formatDaemonWorkerName(worker.index, worker.kind)
                            }}
                            <span class="visually-hidden">
                              · {{ daemonWorkerOccupancy(worker) }} ·
                              {{ worker.agentCount }} agents</span
                            >
                          </span>
                        }
                      </span>
                      <span class="d-block small">
                        <span class="d-flex justify-content-between gap-2 mb-1"
                          ><span>Daemon CPU</span
                          ><span
                            >{{
                              formatDaemonCores(node.measurements?.daemonCores)
                            }}
                            /
                            {{ formatDaemonCores(node.measurements?.cores) }}
                            cores</span
                          ></span
                        >
                        <span
                          class="d-block progress hilos-progress-track"
                          aria-hidden="true"
                          ><span
                            class="progress-bar hilos-progress"
                            [style.--hilos-progress]="
                              daemonProgressWidth(
                                daemonPercentage(
                                  node.measurements?.daemonCores,
                                  node.measurements?.cores
                                )
                              )
                            "
                          ></span
                        ></span>
                        <span class="d-block text-body-secondary mb-2"
                          >{{
                            formatDaemonPercentage(
                              daemonPercentage(
                                node.measurements?.daemonCores,
                                node.measurements?.cores
                              )
                            )
                          }}
                          of available CPU</span
                        >
                        <span class="d-flex justify-content-between gap-2 mb-1"
                          ><span>Measured memory</span
                          ><span
                            >{{ formatDaemonBytes(daemonTotalMemory(node)) }} /
                            {{
                              formatDaemonBytes(
                                node.measurements?.memoryCeiling
                              )
                            }}</span
                          ></span
                        >
                        <span
                          class="d-block progress hilos-progress-track"
                          aria-hidden="true"
                          ><span
                            class="progress-bar hilos-progress"
                            [style.--hilos-progress]="
                              daemonProgressWidth(
                                daemonPercentage(
                                  daemonTotalMemory(node),
                                  node.measurements?.memoryCeiling
                                )
                              )
                            "
                          ></span
                        ></span>
                        <span class="d-block text-body-secondary mb-2"
                          >{{
                            formatDaemonNumber(
                              node.measurements?.workersMeasured
                            )
                          }}
                          worker processes measured</span
                        >
                        <span class="d-block"
                          >Monopolistic:
                          {{
                            formatDaemonNumber(daemonMonopolisticCount(node))
                          }}</span
                        >
                        <span class="d-block text-body-secondary mb-2"
                          >Pool grows on demand</span
                        >
                        <span
                          class="d-block"
                          [attr.data-id]="
                            'hilos-daemon-browsers-' + node.nodeId
                          "
                          >{{
                            node.role === 'slave'
                              ? 'Does not accept browsers'
                              : 'Browser connections: ' +
                                formatDaemonNumber(node.standing?.connections)
                          }}</span
                        >
                      </span>
                    }
                  </a>
                </div>
              }
            </div>
          }
        }

        <section
          class="border rounded bg-body-tertiary p-3 mb-3"
          data-id="hilos-daemon-database"
        >
          <div class="d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-database" aria-hidden="true"></i>
            <h2 class="h6 mb-0">Project database</h2>
            <span
              class="small font-monospace text-break"
              data-id="hilos-daemon-database-address"
              >{{ formatDaemonDatabaseAddress(primary) }}</span
            >
            <span
              class="badge bg-body text-body border"
              data-id="hilos-daemon-database-migration"
              >Migration:
              {{ formatDaemonNumber(primary?.migrationIndex) }}</span
            >
          </div>
          @if (primary?.socket !== null && primary?.socket !== undefined) {
            <p
              class="small text-break mb-0 mt-2"
              data-id="hilos-daemon-database-socket"
            >
              Unix socket: <code>{{ primary?.socket }}</code>
            </p>
          }
          <p class="small text-body-secondary mb-0 mt-2">
            Shared primary connection
          </p>
        </section>
      </div>
    </hilos-admin-page>
  `,
})
export class HilosDaemonPage {
  protected readonly router = inject(HILOS_ROUTER)
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly pages = HilosPages
  protected readonly daemonMonopolisticCount = daemonMonopolisticCount
  protected readonly daemonPercentage = daemonPercentage
  protected readonly daemonProgressWidth = daemonProgressWidth
  protected readonly daemonTotalMemory = daemonTotalMemory
  protected readonly daemonWorkerOccupancy = daemonWorkerOccupancy
  protected readonly formatDaemonBytes = formatDaemonBytes
  protected readonly formatDaemonCores = formatDaemonCores
  protected readonly formatDaemonDatabaseAddress = formatDaemonDatabaseAddress
  protected readonly formatDaemonNodeState = formatDaemonNodeState
  protected readonly formatDaemonNumber = formatDaemonNumber
  protected readonly formatDaemonPercentage = formatDaemonPercentage
  protected readonly formatDaemonWorkerName = formatDaemonWorkerName
  /** The project's existing connection and page scopes. */
  readonly context = input.required<HilosDaemonContext>()
  protected readonly page = HilosPages.DAEMON
  protected readonly daemon = computed(() =>
    createHilosDaemonOverview(this.context()),
  )
  protected readonly overview = signal<HilosDaemonOverviewData | null>(null)
  protected readonly database = signal<HilosDaemonDatabaseData | null>(null)
  protected readonly metricsPath = computed(() => {
    const source = this.overview()?.entryPoints
    return source
      ? this.router.resolvePath(HilosPages.DAEMON_HTTP_SERVER, {
          nodeId: source.nodeId,
        })
      : null
  })
  constructor() {
    effect((onCleanup) => {
      const daemon = this.daemon()
      const stops = [
        mirrorHilosSignal(daemon.overview, this.overview),
        mirrorHilosSignal(daemon.database, this.database),
      ]
      onCleanup(() => {
        stops.forEach((stop) => stop())
      })
    })
  }
  /** The silent node's own last-known silence boundary. */
  protected formatSilentSince(node: HilosDaemonOverviewNode): string | null {
    this.timeZone()
    return formatDaemonNodeSilentSince({ ...node, clustered: true }, Date.now())
  }
}
