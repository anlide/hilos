// HilosSecurityStepUpPage — the framework page of operations that ask for
// confirmation (HilosPages.SECURITY_STEP_UP, HIL-1204), a child of two-factor:
// one row per operation the step-up directory declares, with whether the
// framework or the project declared it and a switch. The table, the row
// view-model and the switch round-trip are the core headless's
// (createHilosSecurityStepUpTable / createHilosSecurityStepUpActions); this view
// owns only the markup, so a project mounts it by passing its
// HilosTwoFactorContext. The switch is a tracked action: a spinner on its own
// row while it flies, the other switches dimmed, and the switch redraws from the
// table's live row. The page heading names the table. The screen is built from
// text: the mockup still draws the list on the two-factor page (D-143).
// Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosSecurityStepUpActions,
  createHilosSecurityStepUpTable,
  HILOS_STEP_UP_ADMIN_COPY,
  HilosPages,
} from '@hilos/core'
import type {
  HilosStepUpOperationRow,
  HilosTwoFactorContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The framework page of operations that ask for confirmation: a switch per operation. */
@Component({
  selector: 'hilos-security-step-up-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosSwitch, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table
        [controller]="operations().controller"
        [dataId]="'hilos-step-up-table'"
      >
        <ng-template hilosTableCell="operationKey" let-row>
          <span
            class="fw-semibold"
            [attr.data-id]="'hilos-step-up-row-' + row.operationKey"
            >{{ row.label }}</span
          >
        </ng-template>
        <ng-template hilosTableCell="owner" let-row>
          <span class="badge text-bg-light border">
            {{
              row.owner === 'framework'
                ? stepUpCopy.framework
                : stepUpCopy.project
            }}
          </span>
        </ng-template>
        <ng-template hilosTableCell="enabled" let-row>
          <hilos-switch
            class="mb-0"
            [checked]="row.enabled"
            [busy]="pendingOperationKey() === row.operationKey"
            [disabled]="operationToggle.busy()"
            [aria-label]="'Require confirmation for ' + row.label"
            [dataId]="'hilos-step-up-switch-' + row.operationKey"
            (toggle)="toggleOperation(row, $event)"
          />
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosSecurityStepUpPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosTwoFactorContext>()

  protected readonly page = HilosPages.SECURITY_STEP_UP
  protected readonly stepUpCopy = HILOS_STEP_UP_ADMIN_COPY

  protected readonly operations = computed(() =>
    createHilosSecurityStepUpTable(this.context()),
  )
  private readonly operationActions = computed(() =>
    createHilosSecurityStepUpActions(this.context()),
  )
  protected readonly operationToggle = createHilosTrackedAction()
  protected readonly pendingOperationKey = signal<string | null>(null)

  constructor() {
    // Bind the table to the connection once the context input is bound; unbind
    // on destroy / swap.
    effect((onCleanup) => {
      const operations = this.operations()
      operations.start()
      onCleanup(() => operations.dispose())
    })
  }

  protected async toggleOperation(
    row: HilosStepUpOperationRow,
    enabled: boolean,
  ): Promise<void> {
    this.pendingOperationKey.set(row.operationKey)
    try {
      await this.operationToggle.run(
        this.operationActions().sendOperationSet(row.operationKey, enabled),
      )
    } finally {
      this.pendingOperationKey.set(null)
    }
  }
}
