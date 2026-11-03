// HilosMaintenanceNodesPage — the Maintenance Nodes tab inside the admin shell
// (HIL-1682): the maintenance reports of the cluster's nodes, as a read-only
// table on its own address under Maintenance's shared section head. Angular
// counterpart of the Vue Nodes page (HIL-1678): the table's window is bound to
// Nodes, the first window arrives with the page's own answer, and a report that
// is missing or silent is explained by the table's merged row message. Whether
// the tab exists at all is the server's word about the cluster mode, carried by
// the shared head; the page adds no condition of its own. The rows and the words
// belong to the core headless; a project supplies only
// HilosMaintenanceContext. Bootstrap classes only.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import { HilosPages } from '@hilos/core/base'
import {
  createHilosMaintenanceNodesTable,
  hilosMaintenanceNodePresentation,
  MAINTENANCE_NODE_ID_FIELD,
  MAINTENANCE_NODE_IS_LEADER_FIELD,
  MAINTENANCE_NODE_PHASE_FIELD,
  MAINTENANCE_NODE_QUIESCED_FIELD,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'

import {
  HilosAdminPage,
  HilosTableCell,
  HilosViewportTable,
} from '@hilos/angular/base'

/**
 * The reports of the cluster nodes as a standard read-only table: only the node
 * is sortable, and nothing here writes.
 */
@Component({
  selector: 'hilos-maintenance-nodes-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Maintenance pages"
    >
      <hilos-viewport-table
        dataId="hilos-maintenance-nodes-table"
        [controller]="nodes().controller"
      >
        <ng-template [hilosTableCell]="fields.nodeId" let-row>
          <span
            class="d-inline-flex flex-wrap gap-1 text-break"
            [attr.data-id]="'hilos-maintenance-nodes-row-' + row.nodeId"
          >
            @if (row.nodeName !== null) {
              <span>{{ row.nodeName }}</span>
              <span class="font-monospace text-body-secondary small">{{
                row.nodeId
              }}</span>
            } @else {
              <span class="font-monospace">{{ row.nodeId }}</span>
            }
          </span>
        </ng-template>
        <ng-template [hilosTableCell]="fields.quiesced" let-row>
          @let quiesced = present(row).quiesced;
          <span
            [class]="quiesced.tone ? 'text-' + quiesced.tone + '-emphasis' : ''"
            >{{ quiesced.text }}</span
          >
        </ng-template>
        <ng-template [hilosTableCell]="fields.phase" let-row>
          @let phase = present(row).phase;
          <span [class]="phase.badge ? 'badge text-bg-secondary' : ''">{{
            phase.text
          }}</span>
        </ng-template>
        <ng-template [hilosTableCell]="fields.role" let-row>{{
          present(row).role
        }}</ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosMaintenanceNodesPage {
  /** The project context: scope stores, the connection and the action lifecycle. */
  readonly context = input.required<HilosMaintenanceContext>()

  protected readonly page = HilosPages.MAINTENANCE_NODES
  protected readonly present = hilosMaintenanceNodePresentation
  protected readonly fields = {
    nodeId: MAINTENANCE_NODE_ID_FIELD,
    quiesced: MAINTENANCE_NODE_QUIESCED_FIELD,
    phase: MAINTENANCE_NODE_PHASE_FIELD,
    role: MAINTENANCE_NODE_IS_LEADER_FIELD,
  } as const

  protected readonly nodes = computed(() =>
    createHilosMaintenanceNodesTable(
      this.context(),
      HilosPages.MAINTENANCE_NODES,
    ),
  )

  constructor() {
    // Bind the table to Nodes — consuming the window of the page's own answer; a
    // context change or leaving the page drops every listener of the table.
    effect((onCleanup) => {
      const nodes = this.nodes()
      nodes.start()
      onCleanup(() => nodes.dispose())
    })
  }
}
