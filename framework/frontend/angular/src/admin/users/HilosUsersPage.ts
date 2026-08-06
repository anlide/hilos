// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic and the row view-model are
// the core headless's (createHilosUsersTable / HilosUserRow); this view owns only
// the column set and the cell markup, so a project mounts it by passing its
// HilosUsersContext. The framework owns every cell except the trailing actions
// cell, which a project fills through an `<ng-template #rowActions let-row>` (e.g.
// a link to the detail page). Bootstrap classes only (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  effect,
  input,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import {
  HilosPages,
  USER_ONLINE_SESSION_COUNT_FIELD,
  USER_PRESENCE_FIELD,
  createHilosUsersTable,
} from '@hilos/core'
import type {
  HilosTableColumnOf,
  HilosUserRow,
  HilosUsersContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** The context a HilosUsersPage `#rowActions` template receives. */
export interface UsersRowActionsContext {
  /** The resolved user row (the template's implicit `let-row`). */
  $implicit: HilosUserRow
}

const COLUMNS: HilosTableColumnOf<HilosUserRow>[] = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: USER_PRESENCE_FIELD, label: 'Presence', sortable: true },
  {
    key: USER_ONLINE_SESSION_COUNT_FIELD,
    label: 'Sessions',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'lastActivity', label: 'Last activity', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/** The framework users admin page: the searchable, sortable users table. */
@Component({
  selector: 'hilos-users-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosViewportTable, NgTemplateOutlet],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-viewport-table
        label="Users"
        [controller]="users().controller"
        [columns]="columns"
        [searchable]="true"
        searchPlaceholder="Search users…"
        emptyText="No users yet."
      >
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
            @if (rowActions(); as tpl) {
              <ng-container
                [ngTemplateOutlet]="tpl"
                [ngTemplateOutletContext]="{ $implicit: row }"
              />
            }
          </td>
        </ng-template>
      </hilos-viewport-table>
    </hilos-admin-page>
  `,
})
export class HilosUsersPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USERS
  protected readonly columns = COLUMNS
  protected readonly users = computed(() =>
    createHilosUsersTable(this.context()),
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
