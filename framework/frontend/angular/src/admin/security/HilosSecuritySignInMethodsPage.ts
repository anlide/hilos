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
// from). Under the table, where the project wired a passkey, one more switch says
// whether a passkey may start an account on an unconfirmed address (HIL-1105): it
// follows the live value that arrives with the set, shares the busy guard of the
// method switches, and is drawn even while the passkey method is switched off.
// The screen is built from text: the mockup has no node for it yet (D-093), nor
// for the passkey block (D-122). Bootstrap classes only (styling-rules.md).
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
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** Numbers the passkey hint of each mounted page, so two pages never share an id. */
let passkeyHintSeq = 0

/** The framework sign-in methods page: the methods table with a switch per method. */
@Component({
  selector: 'hilos-security-sign-in-methods-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosLink,
    HilosSwitch,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="methods().controller">
        <ng-template hilosTableCell="methodKey" let-row>
          <div class="fw-semibold">{{ row.label }}</div>
          <code class="small text-body-secondary">{{ row.methodKey }}</code>
        </ng-template>
        <ng-template hilosTableCell="enabled" let-row>
          <hilos-switch
            class="mb-0"
            [checked]="isOn(row)"
            [busy]="pendingMethodKey() === row.methodKey"
            [disabled]="toggle.busy()"
            [aria-label]="'Enable ' + row.label"
            [dataId]="'hilos-sign-in-method-enabled-' + row.methodKey"
            (toggle)="onToggle(row, $event)"
          />
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
      @if (passkeyWired()) {
        <div class="d-flex align-items-center gap-3 py-3 border-top">
          <div class="flex-grow-1">
            <div class="fw-semibold small">
              Passkey without a confirmed address
            </div>
            <div [id]="passkeyHintId" class="small text-body-secondary">
              A new account may start with only a passkey, before its email or
              phone is confirmed. Turning this off stops new accounts only:
              those already created keep signing in with their passkey.
            </div>
          </div>
          <hilos-switch
            class="mb-0"
            [checked]="passkeyAllowsUnproven()"
            [busy]="passkeyPending()"
            [disabled]="toggle.busy()"
            [aria-label]="'Allow passkey without a confirmed address'"
            [describedBy]="passkeyHintId"
            dataId="hilos-sign-in-passkey-unproven"
            (toggle)="onTogglePasskeyUnproven($event)"
          />
        </div>
      }
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
  // The passkey row and the passkey policy, mirrored the same way (HIL-1105).
  protected readonly passkeyWired = signal(false)
  protected readonly passkeyAllowsUnproven = signal(false)
  protected readonly passkeyHintId = `hilos-sign-in-passkey-hint-${passkeyHintSeq++}`

  // One tracked runner for every switch: a single in-flight guard across rows is
  // enough, and the busy flag disables every switch while one write is settling.
  protected readonly toggle = createHilosTrackedAction()
  protected readonly pendingMethodKey = signal<string | null>(null)
  protected readonly passkeyPending = signal(false)

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound, and follow the live enabled set,
    // the passkey row and the passkey policy; unbind on destroy or context swap.
    effect((onCleanup) => {
      const methods = this.methods()
      methods.start()
      this.enabledKeys.set(methods.enabledKeys.get())
      this.passkeyWired.set(methods.passkeyWired.get())
      this.passkeyAllowsUnproven.set(methods.passkeyAllowsUnproven.get())
      const unsubscribers = [
        subscribeSignal(methods.enabledKeys, (keys) =>
          this.enabledKeys.set(keys),
        ),
        subscribeSignal(methods.passkeyWired, (wired) =>
          this.passkeyWired.set(wired),
        ),
        subscribeSignal(methods.passkeyAllowsUnproven, (allowed) =>
          this.passkeyAllowsUnproven.set(allowed),
        ),
      ]
      onCleanup(() => {
        for (const unsubscribe of unsubscribers) {
          unsubscribe()
        }
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

  // Dispatch the switch as a tracked action. Nothing is set optimistically: the
  // switch follows the live set on success and remains on it after a refusal.
  protected async onToggle(
    row: HilosSignInMethodRow,
    next: boolean,
  ): Promise<void> {
    this.pendingMethodKey.set(row.methodKey)
    try {
      await this.toggle.run(this.actions().sendMethodSet(row.methodKey, next))
    } finally {
      this.pendingMethodKey.set(null)
    }
  }

  // The passkey policy switch rides the same runner, with the same rule: nothing
  // optimistic, the switch moves when the new value arrives with the set.
  protected async onTogglePasskeyUnproven(next: boolean): Promise<void> {
    this.passkeyPending.set(true)
    try {
      await this.toggle.run(this.actions().sendPasskeyUnprovenSet(next))
    } finally {
      this.passkeyPending.set(false)
    }
  }
}
