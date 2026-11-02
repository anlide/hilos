// The standard server viewport owns search, state facets and the Apply boundary.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import { HilosPages } from '@hilos/core/base'
import {
  createHilosStatusComponentsTable,
  HILOS_STATUS_COMPONENTS_COPY,
  STATUS_COMPONENT_NAME_FIELD,
  STATUS_COMPONENT_AGENT_TYPE_FIELD,
  STATUS_COMPONENT_STATE_FIELD,
  type HilosStatusContext,
  type HilosStatusComponentState,
} from '@hilos/core/admin/status'
import {
  HilosAdminPage,
  HilosViewportTable,
  HilosTableCell,
} from '@hilos/angular/base'

/** Read-only component health in the project's declaration order. */
@Component({
  selector: 'hilos-status-components-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, HilosTableCell],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Status pages">
      <hilos-viewport-table
        [controller]="table().controller"
        data-id="hilos-status-components-table"
      >
        <ng-template [hilosTableCell]="nameField" let-row>
          <div
            [attr.data-id]="'hilos-status-component-' + row.componentKey"
            class="text-break"
          >
            <span class="fw-semibold">{{ row.name }}</span>
            @if (row.description !== null) {
              <div class="small text-body-secondary">{{ row.description }}</div>
            }
          </div>
        </ng-template>
        <ng-template [hilosTableCell]="agentTypeField" let-row>
          <code class="text-break">{{ row.agentType }}</code>
        </ng-template>
        <ng-template [hilosTableCell]="stateField" let-row>
          <span
            class="badge text-wrap"
            [class]="stateClasses[row.state]"
            [attr.data-id]="'hilos-status-component-state-' + row.componentKey"
            >{{ stateLabel(row.state) }}</span
          >
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosStatusComponentsPage {
  /** The project's existing scopes, connection and action lifecycle. */
  readonly context = input.required<HilosStatusContext>()

  protected readonly page = HilosPages.STATUS_COMPONENTS
  protected readonly copy = HILOS_STATUS_COMPONENTS_COPY
  protected readonly nameField = STATUS_COMPONENT_NAME_FIELD
  protected readonly agentTypeField = STATUS_COMPONENT_AGENT_TYPE_FIELD
  protected readonly stateField = STATUS_COMPONENT_STATE_FIELD
  protected readonly stateClasses: Readonly<Record<string, string>> = {
    operational: 'text-bg-success',
    checking: 'text-bg-secondary',
    partial_outage: 'text-bg-warning',
    major_outage: 'text-bg-danger',
    maintenance: 'text-bg-info',
    unknown: 'bg-body-secondary text-body-emphasis',
  }
  protected readonly table = computed(() =>
    createHilosStatusComponentsTable(this.context()),
  )

  constructor() {
    effect((onCleanup) => {
      const table = this.table()
      table.start()
      onCleanup(() => table.dispose())
    })
  }

  /** The projected cell's state, narrowed at the template boundary. */
  protected stateLabel(state: HilosStatusComponentState): string {
    return this.copy.states[state]
  }
}
