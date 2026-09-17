// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic, the row view-model, and the
// frame the table declares — its columns, search, and empty words — are the core
// headless's (createHilosUsersTable / HilosUserRow); this view owns only the cell
// markup, so a project mounts it by passing its HilosUsersContext. The framework
// owns every cell except the trailing actions cell, which a project fills through
// an `<ng-template #rowActions let-row>` (e.g. a link to the detail page) — the
// framework's own row action, the takeover, is drawn ahead of it. Bootstrap classes
// only (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  input,
  signal,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import {
  HilosPages,
  createHilosImpersonate,
  createHilosUsersTable,
  subscribeSignal,
} from '@hilos/core'
import type { HilosUserRow, HilosUsersContext } from '@hilos/core'

import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { createHilosTrackedAction } from '../../hilosTrackedAction.js'

/** The context a HilosUsersPage `#rowActions` template receives. */
export interface UsersRowActionsContext {
  /** The resolved user row (the template's implicit `let-row`). */
  $implicit: HilosUserRow
}

/** The framework users admin page: the searchable, sortable users table. */
@Component({
  selector: 'hilos-users-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosAdminPage,
    HilosModal,
    HilosViewportTable,
    LoadingButton,
    NgTemplateOutlet,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="users().controller">
        <ng-template #row let-row>
          <td class="text-body-secondary">{{ row.id }}</td>
          <td class="fw-medium">{{ row.name }}</td>
          <td>
            <span
              [class]="
                'badge ' +
                (row.presence === 'online'
                  ? 'text-bg-success'
                  : 'text-bg-secondary')
              "
              >{{ row.presence }}</span
            >
          </td>
          <td class="text-end">{{ row.onlineSessionCount }}</td>
          <td>{{ row.lastActivity ?? '—' }}</td>
          <td class="text-end">
            @if (row.id !== currentUserId()) {
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary me-2"
                title="Impersonate"
                aria-label="Impersonate"
                [attr.data-id]="'hilos-users-impersonate-' + row.id"
                (click)="openImpersonate(row)"
              >
                <i class="bi bi-person-badge" aria-hidden="true"></i>
              </button>
            }
            @if (rowActions(); as tpl) {
              <ng-container
                [ngTemplateOutlet]="tpl"
                [ngTemplateOutletContext]="{ $implicit: row }"
              />
            }
          </td>
        </ng-template>
      </hilos-viewport-table>

      <hilos-modal
        [open]="impersonateOpen()"
        (openChange)="impersonateOpen.set($event)"
        [title]="impersonateTitle()"
        [closeOnBackdrop]="!takeover.busy()"
        [closeOnEsc]="!takeover.busy()"
        initialFocus="dialog"
      >
        <hilos-action-error [action]="takeover" />
        @if (impersonateRow(); as row) {
          <p class="mb-0">
            Become <strong>{{ row.name }}</strong> and see the app as they do?
            You can stop from the banner at any time.
          </p>
        }
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="takeover.busy()"
            data-id="hilos-users-impersonate-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="takeover.loading()"
            [disabled]="takeover.busy()"
            data-id="hilos-users-impersonate-confirm"
            (click)="submitImpersonate()"
          >
            Impersonate
          </button>
        </ng-template>
      </hilos-modal>
    </hilos-admin-page>
  `,
})
export class HilosUsersPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USERS
  protected readonly users = computed(() =>
    createHilosUsersTable(this.context()),
  )
  protected readonly rowActions =
    contentChild<TemplateRef<UsersRowActionsContext>>('rowActions')

  // The takeover: a confirm modal before an admin assumes a user's identity, as
  // every mutation on this project is confirmed in one. The button is on every row
  // but your own — taking yourself over is refused server-side, and a control whose
  // only outcome is a refusal is not one.
  private readonly impersonate = computed(() =>
    createHilosImpersonate(this.context()),
  )
  protected readonly currentUserId = signal<number | null>(null)
  protected readonly impersonateOpen = signal(false)
  protected readonly impersonateRow = signal<HilosUserRow | null>(null)
  protected readonly takeover = createHilosTrackedAction()

  protected readonly impersonateTitle = computed(() => {
    const row = this.impersonateRow()

    return row ? `Impersonate · ${row.name}` : 'Impersonate user'
  })

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const users = this.users()
      users.start()
      onCleanup(() => users.dispose())
    })

    // Mirror the session's own user id, which the context is needed to reach, so
    // the effect re-subscribes if the context is ever swapped.
    effect((onCleanup) => {
      onCleanup(
        subscribeSignal(this.impersonate().currentUserId, (id) =>
          this.currentUserId.set(id),
        ),
      )
    })
  }

  protected openImpersonate(row: HilosUserRow): void {
    this.takeover.clearError()
    this.impersonateRow.set(row)
    this.impersonateOpen.set(true)
  }

  // Authoritative-backend: the visible effect — the shell banner, and this admin
  // session becoming the non-admin target, which drops this admin-only page — is
  // server-driven through the handshake broadcast, so a success only closes the
  // confirm; a refusal keeps it open with the sentence the page sent back.
  protected async submitImpersonate(): Promise<void> {
    const row = this.impersonateRow()
    if (!row || this.takeover.busy()) {
      return
    }
    if (await this.takeover.run(this.impersonate().start(row.id))) {
      this.impersonateOpen.set(false)
    }
  }
}
