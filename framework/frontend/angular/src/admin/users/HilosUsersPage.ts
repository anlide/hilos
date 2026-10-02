// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic, the row view-model, and the
// frame the table declares — its columns, search, and empty words — are the core
// headless's (createHilosUsersTable / HilosUserRow); this view owns only the cell
// markup, so a project mounts it by passing its HilosUsersContext. The framework
// owns every cell except the trailing actions cell, which a project fills through
// an `<ng-template #rowActions let-row>` (e.g. a link to the detail page); the
// takeover lives on the person's card since HIL-1170. The bar's "Past deadline
// on" filter narrows the list to the people past one legal document's deadline
// and lives in the address too (`/hilos/users/terms`, HIL-945): the legal
// section's root links its count there, and changing the filter rewrites the
// address. Bootstrap classes only (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  inject,
  input,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { HilosPages, createHilosUsersTable } from '@hilos/core'
import type { HilosUserRow, HilosUsersContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

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
    HilosAdminPage,
    HilosHideable,
    HilosTableCell,
    HilosViewportTable,
    NgTemplateOutlet,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table [controller]="users().controller">
        <ng-template hilosTableCell="id" let-row>{{ row.id }}</ng-template>
        <ng-template hilosTableCell="name" let-row
          ><hilos-hideable [value]="row.name"
        /></ng-template>
        <ng-template hilosTableCell="presence" let-row>
          <span
            [class]="
              'badge ' +
              (row.presence === 'online'
                ? 'text-bg-success'
                : 'text-bg-secondary')
            "
            >{{ row.presence }}</span
          >
        </ng-template>
        <ng-template hilosTableCell="onlineSessionCount" let-row>{{
          row.onlineSessionCount
        }}</ng-template>
        <ng-template hilosTableCell="lastActivity" let-row>{{
          row.lastActivity ?? '—'
        }}</ng-template>
        <ng-template hilosTableCell="actions" let-row>
          @if (rowActions(); as tpl) {
            <ng-container
              [ngTemplateOutlet]="tpl"
              [ngTemplateOutletContext]="{ $implicit: row }"
            />
          }
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosUsersPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USERS
  // The lapsed filter is read from the address the page opened on and written
  // back as it changes; mounted without a navigator, the list opens whole and
  // leaves the address alone.
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  protected readonly users = computed(() =>
    createHilosUsersTable(this.context(), this.router ?? undefined),
  )
  protected readonly rowActions =
    contentChild<TemplateRef<UsersRowActionsContext>>('rowActions')

  constructor() {
    // Bind the server-windowed table to the connection and request the first
    // window once the context input is bound; unbind on destroy or context swap.
    effect((onCleanup) => {
      const users = this.users()
      users.start()
      onCleanup(() => users.dispose())
    })
  }
}
