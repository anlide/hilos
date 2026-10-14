import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  DAEMON_PLACEMENT_TYPE_FIELD,
  DAEMON_PLACEMENT_PLACEMENT_FIELD,
  DAEMON_PLACEMENT_NODES_FIELD,
  DAEMON_PLACEMENT_UNPLACED_FIELD,
  daemonPlacementCellIds,
  daemonPlacementNodeColumnKey,
  formatDaemonNumber,
  formatDaemonPlacementKind,
  formatDaemonPlacementState,
  type HilosDaemonOverviewData,
  type HilosDaemonPlacementRow,
  type HilosDaemonPlacementView,
  type HilosDaemonPlacementNode,
  type HilosDaemonPlacementSelection,
} from '@hilos/core'

import { HilosSkeleton } from '../../HilosSkeleton.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosTableDetail } from '../../HilosTableDetail.js'
import { mirrorHilosSignal } from '../../hilosSignal.js'

/** The root's matrix window, with one inline detail owned by its headless view. */
@Component({
  selector: 'hilos-daemon-placement',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosSkeleton,
    HilosViewportTable,
    HilosTableCell,
    HilosTableDetail,
  ],
  template: `
    <div data-id="hilos-daemon-placement-view">
      @if (overview(); as picture) {
        <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
          <div class="col">
            <section class="border rounded p-3 h-100">
              <h2 class="h6">Running instances</h2>
              <div class="fs-3" data-id="hilos-daemon-placement-instances">
                {{ formatDaemonNumber(placement()?.instances) }}
              </div>
              <p class="small text-body-secondary mb-0">Instances, not types</p>
            </section>
          </div>
          <div class="col">
            <section class="border rounded p-3 h-100">
              <h2 class="h6">Agent types</h2>
              <div class="fs-3" data-id="hilos-daemon-placement-types">
                {{ formatDaemonNumber(placement()?.types) }}
              </div>
              <p class="small text-body-secondary mb-0">
                Declared in the project
              </p>
            </section>
          </div>
          <div class="col">
            <section class="border rounded p-3 h-100">
              <h2 class="h6">One per cluster</h2>
              <div class="fs-3" data-id="hilos-daemon-placement-cluster-types">
                {{ formatDaemonNumber(placement()?.clusterTypes) }}
              </div>
              <p class="small text-body-secondary mb-0">
                Declared in the project
              </p>
            </section>
          </div>
          <div class="col">
            <section class="border rounded p-3 h-100">
              <h2 class="h6">Unplaced</h2>
              <div
                class="fs-3"
                [class.text-danger-emphasis]="
                  (unplacedTypes()?.length ?? 0) > 0
                "
                data-id="hilos-daemon-placement-unplaced"
              >
                {{ formatDaemonNumber(unplacedTypes()?.length) }}
              </div>
              <p class="small text-body-secondary mb-0">
                {{
                  unplacedTypes() === null
                    ? 'Placement status unknown'
                    : unplacedTypes()?.length === 0
                      ? 'All placed'
                      : picture.clustered
                        ? 'Leader could not place these types'
                        : 'Could not place these types'
                }}
              </p>
            </section>
          </div>
        </div>
        <hilos-viewport-table [controller]="view().controller">
          <ng-template [hilosTableCell]="typeField" let-row
            ><code>{{ row.agentType }}</code></ng-template
          >
          <ng-template [hilosTableCell]="placementField" let-row>{{
            formatDaemonPlacementKind(row, picture.clustered)
          }}</ng-template>
          <ng-template [hilosTableCell]="unplacedField" let-row>
            @if (formatDaemonPlacementState(row); as state) {
              <span class="badge text-bg-danger">{{ state }}</span>
            }
          </ng-template>
          @for (node of nodes(); track node.nodeId) {
            <ng-template
              [hilosTableCell]="daemonPlacementNodeColumnKey(node.nodeId)"
              let-row
            >
              @if ((cellIds(row, node.nodeId)?.length ?? 0) > 0) {
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  [attr.aria-label]="
                    row.agentType +
                    ' on ' +
                    node.nodeId +
                    ': ' +
                    cellIds(row, node.nodeId)?.length +
                    ' instances'
                  "
                  [attr.aria-expanded]="
                    selectedCell()?.rowKey === row.rowKey &&
                    selectedCell()?.nodeId === node.nodeId
                  "
                  [attr.data-id]="
                    'hilos-daemon-placement-cell-' +
                    row.rowKey +
                    '-' +
                    node.nodeId
                  "
                  (click)="view().openCell(row.rowKey, node.nodeId)"
                >
                  {{ cellIds(row, node.nodeId)?.length }}
                </button>
              } @else {
                <span
                  class="text-body-secondary"
                  [title]="
                    cellIds(row, node.nodeId) === null
                      ? 'Node is silent or has not reported its agents'
                      : ''
                  "
                  >{{ cellIds(row, node.nodeId) === null ? '?' : '·' }}</span
                >
              }
            </ng-template>
          }
          <ng-template [hilosTableDetail]="nodesField" let-row>
            @if (selectedCell(); as selected) {
              @if (selected.rowKey === row.rowKey) {
                <section
                  class="rounded bg-body-tertiary p-2"
                  data-id="hilos-daemon-placement-selection"
                  aria-label="Selected agent instances"
                >
                  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h2 class="h6 mb-0 text-break">
                      <code>{{ selected.agentType }}</code>
                    </h2>
                    <span
                      >on
                      <code class="text-break">{{
                        selected.nodeId
                      }}</code></span
                    ><span class="badge bg-body text-body border"
                      >{{ selected.agentIds.length }} instances</span
                    >
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-secondary ms-auto"
                      data-id="hilos-daemon-placement-collapse"
                      (click)="view().closeCell()"
                    >
                      Collapse
                    </button>
                  </div>
                  <div class="d-flex flex-wrap gap-2">
                    @for (
                      id of selected.agentIds;
                      track selectedCell()?.nodeId + ':' + id
                    ) {
                      <code class="text-break">{{ id }}</code>
                    }
                  </div>
                </section>
              }
            }
          </ng-template>
        </hilos-viewport-table>
        <div data-id="hilos-daemon-placement-alerts" class="mt-3">
          @for (agentType of unplacedTypes() ?? []; track agentType) {
            <p class="alert alert-danger py-2 small text-break">
              <code>{{ agentType }}</code> is unplaced
            </p>
          }
        </div>
      } @else {
        <p class="text-body-secondary">Waiting for the node picture…</p>
        <hilos-skeleton />
      }
    </div>
  `,
})
export class HilosDaemonPlacement {
  /** The matrix controller owned and started by the root. */
  readonly view = input.required<HilosDaemonPlacementView>()
  /** Complete totals and node roster, independent of matrix search. */
  readonly overview = input.required<HilosDaemonOverviewData | null>()
  protected readonly typeField = DAEMON_PLACEMENT_TYPE_FIELD
  protected readonly placementField = DAEMON_PLACEMENT_PLACEMENT_FIELD
  protected readonly unplacedField = DAEMON_PLACEMENT_UNPLACED_FIELD
  protected readonly nodesField = DAEMON_PLACEMENT_NODES_FIELD
  protected readonly nodes = signal<readonly HilosDaemonPlacementNode[]>([])
  protected readonly selectedCell =
    signal<HilosDaemonPlacementSelection | null>(null)
  protected readonly placement = computed(() => this.overview()?.placement)
  protected readonly unplacedTypes = computed(
    () => this.placement()?.unplacedTypes ?? null,
  )
  protected readonly knownNodes = computed(
    () =>
      new Set(
        (this.overview()?.nodes ?? [])
          .filter((node) => node.online && node.workers !== null)
          .map((node) => node.nodeId),
      ),
  )
  protected readonly daemonPlacementNodeColumnKey = daemonPlacementNodeColumnKey
  protected readonly formatDaemonNumber = formatDaemonNumber
  protected readonly formatDaemonPlacementKind = formatDaemonPlacementKind
  protected readonly formatDaemonPlacementState = formatDaemonPlacementState
  constructor() {
    effect((onCleanup) => {
      const view = this.view()
      const stops = [
        mirrorHilosSignal(view.nodes, this.nodes),
        mirrorHilosSignal(view.selectedCell, this.selectedCell),
      ]
      onCleanup(() => stops.forEach((stop) => stop()))
    })
  }
  protected cellIds(
    row: HilosDaemonPlacementRow,
    nodeId: string,
  ): readonly string[] | null {
    return this.knownNodes().has(nodeId)
      ? daemonPlacementCellIds(row, nodeId)
      : null
  }
}
