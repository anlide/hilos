// HilosSecuritySignInMethodsPage — the framework sign-in methods page
// (HilosPages.SECURITY_SIGN_IN_METHODS, HIL-427): one row per method the project
// wired, each with a switch, whether the installation can serve it, and — for a
// provider — a link to its own screen. The table, the row view-model and the switch
// round-trip are the core headless's (createHilosSignInMethodsTable /
// createHilosSignInMethodsActions); this view owns only the markup, so a project
// mounts it by passing its HilosSignInMethodsContext. The context arrives via input
// and carries core signals, so an effect binds the table once it is bound and
// mirrors the live enabled set into an Angular signal.
// The switch is a tracked action: it dispatches the one-method switch, the refusal
// toasts ("at least one sign-in method must stay on") and the switch goes back, and
// the switch redraws from the live enabled set when it arrives — that set, not the
// row, is what says a method is on (the same set every sign-in surface reshapes
// from). The screen is built from text: the mockup has no node for it yet (D-093).
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
  HilosPages,
  createHilosSignInMethodsActions,
  createHilosSignInMethodsTable,
  resolveHilosPath,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosSignInMethodRow,
  HilosSignInMethodsContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The framework sign-in methods page: the methods table with a switch per method. */
@Component({
  selector: 'hilos-security-sign-in-methods-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="methods().controller">
        <ng-template hilosTableCell="methodKey" let-row>
          <div class="fw-semibold">{{ row.label }}</div>
          <code class="small text-body-secondary">{{ row.methodKey }}</code>
        </ng-template>
        <ng-template hilosTableCell="enabled" let-row>
          <div class="form-check form-switch mb-0">
            <input
              type="checkbox"
              class="form-check-input"
              role="switch"
              [checked]="isOn(row)"
              [disabled]="toggle.busy()"
              [attr.aria-label]="'Enable ' + row.label"
              [attr.data-id]="'hilos-sign-in-method-enabled-' + row.methodKey"
              (change)="onToggle(row, $event)"
            />
          </div>
        </ng-template>
        <ng-template hilosTableCell="ready" let-row>
          @if (row.ready) {
            <span class="badge text-bg-success-subtle text-success-emphasis">
              {{ row.providerKey === null ? 'Ready' : 'Configured' }}
            </span>
          } @else {
            <span class="badge text-bg-warning-subtle text-warning-emphasis">
              {{
                row.providerKey === null
                  ? 'Nothing to send with'
                  : 'Not configured'
              }}
            </span>
          }
          @if (row.providerKey !== null) {
            <a
              hilosLink
              [hilosLink]="providerPath(row.providerKey)"
              class="btn btn-sm btn-link"
              [attr.data-id]="'hilos-sign-in-method-provider-' + row.methodKey"
            >
              Configure
            </a>
          }
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosSecuritySignInMethodsPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosSignInMethodsContext>()

  protected readonly page = HilosPages.SECURITY_SIGN_IN_METHODS

  protected readonly methods = computed(() =>
    createHilosSignInMethodsTable(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSignInMethodsActions(this.context()),
  )

  // The live enabled set, mirrored from the core table handle: the handle arrives
  // through a computed, so hilosSignal cannot take it at field init.
  private readonly enabledKeys = signal<readonly string[]>([])

  // One tracked runner for every switch: a single in-flight guard across rows is
  // enough, and the busy flag disables every switch while one write is settling.
  protected readonly toggle = createHilosTrackedAction()

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound, and follow the live enabled set;
    // unbind on destroy or context swap.
    effect((onCleanup) => {
      const methods = this.methods()
      methods.start()
      this.enabledKeys.set(methods.enabledKeys.get())
      const unsubscribe = subscribeSignal(methods.enabledKeys, (keys) =>
        this.enabledKeys.set(keys),
      )
      onCleanup(() => {
        unsubscribe()
        methods.dispose()
      })
    })
  }

  /** Whether the method is on now, by the live set rather than the row. */
  protected isOn(row: HilosSignInMethodRow): boolean {
    return this.enabledKeys().includes(row.methodKey)
  }

  /** The provider's own screen (its {providerId} route param is the key). */
  protected providerPath(providerKey: string): string {
    return resolveHilosPath(HilosPages.SECURITY_OAUTH_PROVIDER, {
      providerId: providerKey,
    })
  }

  // Dispatch the switch as a tracked action. Nothing is set optimistically: on
  // success the switch follows the set when it arrives, and on a refusal the box
  // the person clicked is put back to what the set still says.
  protected async onToggle(
    row: HilosSignInMethodRow,
    event: Event,
  ): Promise<void> {
    const box = event.target as HTMLInputElement
    const ok = await this.toggle.run(
      this.actions().sendMethodSet(row.methodKey, box.checked),
    )
    if (!ok) {
      box.checked = this.isOn(row)
    }
  }
}
