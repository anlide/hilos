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
  input,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { HilosPages, createHilosUsersTable } from '@hilos/core'
import type {
  HilosTableColumn,
  HilosUserRow,
  HilosUsersContext,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosTable } from '../../HilosTable.js'

/** The context a HilosUsersPage `#rowActions` template receives. */
export interface UsersRowActionsContext {
  /** The resolved user row (the template's implicit `let-row`). */
  $implicit: HilosUserRow
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'presence', label: 'Presence', sortable: true },
  {
    key: 'onlineSessionCount',
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
  imports: [HilosAdminPage, HilosTable, NgTemplateOutlet],
  template: `
    <hilos-admin-page [page]="page">
      <hilos-table
        [controller]="usersTable()"
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
      </hilos-table>
    </hilos-admin-page>
  `,
})
export class HilosUsersPage {
  /** The project context: scope stores, connection, and the user collection. */
  readonly context = input.required<HilosUsersContext>()

  protected readonly page = HilosPages.USERS
  protected readonly columns = COLUMNS
  protected readonly usersTable = computed(() =>
    createHilosUsersTable(this.context()),
  )
  protected readonly rowActions =
    contentChild<TemplateRef<UsersRowActionsContext>>('rowActions')
}
