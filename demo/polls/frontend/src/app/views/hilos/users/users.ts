// The Hilos users list page (HilosPages.USERS): a thin project binding of the
// framework HilosUsersPage to this app's context. The table, the row view-model,
// and search/sort/paging are the framework's; the project supplies the context and
// fills the trailing actions cell through the `#rowActions` template — here, a link
// to the user detail page. Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLink, HilosUsersPage } from '@hilos/angular'
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'

import { hilosUsersContext } from './hilosUsersContext'

@Component({
  selector: 'app-users',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosUsersPage, HilosLink],
  template: `
    <hilos-users-page [context]="context">
      <ng-template #rowActions let-row>
        <a
          [hilosLink]="userHref(row.id)"
          class="btn btn-sm btn-outline-primary"
          [attr.data-id]="'hilos-users-open-' + row.id"
        >
          Open
        </a>
      </ng-template>
    </hilos-users-page>
  `,
})
export class Users {
  protected readonly context = hilosUsersContext

  /**
   * The detail route for one user, from the framework page catalog.
   *
   * @param id The user id to open.
   */
  protected userHref(id: number): string {
    return HILOS_PAGE_ROUTES[HilosPages.USER].replace('{userId}', String(id))
  }
}
