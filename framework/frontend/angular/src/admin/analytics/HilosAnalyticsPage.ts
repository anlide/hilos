// HilosAnalyticsPage — the Analytics section overview. In Phase-1 it shows an
// empty state with a line and a link to the admin users list; overview content
// follows in HIL-1402. The core owns the shared words and the admin shell draws
// the page identity. Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component, inject } from '@angular/core'
import {
  ANALYTICS_OVERVIEW_EMPTY_TEXT,
  ANALYTICS_OVERVIEW_OPEN_USERS_LABEL,
  HilosPages,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'

/** The Analytics section overview: the empty state and the way to the users list. */
@Component({
  selector: 'hilos-analytics-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink],
  template: `
    <hilos-admin-page [page]="page">
      <div
        class="border rounded p-4 text-center text-body-secondary"
        data-id="hilos-analytics-overview-empty"
      >
        <i class="bi bi-inbox fs-2 d-block mb-2" aria-hidden="true"></i>
        <p>{{ emptyText }}</p>
        @if (usersPath !== undefined) {
          <a
            [hilosLink]="usersPath"
            class="btn btn-sm btn-outline-secondary"
            data-id="hilos-analytics-overview-open-users"
            >{{ openUsersLabel }}</a
          >
        }
      </div>
    </hilos-admin-page>
  `,
})
export class HilosAnalyticsPage {
  /** The admin page key the shell draws the identity of. */
  protected readonly page = HilosPages.ANALYTICS
  protected readonly emptyText = ANALYTICS_OVERVIEW_EMPTY_TEXT
  protected readonly openUsersLabel = ANALYTICS_OVERVIEW_OPEN_USERS_LABEL

  private readonly router = inject(HILOS_ROUTER)
  protected readonly usersPath = this.router.resolvePath(HilosPages.USERS, {})
}
