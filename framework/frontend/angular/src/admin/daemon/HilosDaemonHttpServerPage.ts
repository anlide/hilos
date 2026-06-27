// HilosDaemonHttpServerPage — the Daemon Http Server admin page (HilosPages.DAEMON_HTTP_SERVER). A framework default: a
// thin binding of the page key to the shared admin shell HilosAdminPage, which
// resolves the heading, lead, breadcrumb, and any sub-section cards from the
// @hilos/core admin tree. Implement the page by projecting content into the
// shell's slot to replace its default body. Bootstrap classes only
// (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosPages } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'

/** The Daemon Http Server admin page: the framework default shell for its key. */
@Component({
  selector: 'hilos-daemon-http-server-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: `<hilos-admin-page [page]="page" />`,
})
export class HilosDaemonHttpServerPage {
  /** The admin page key this default renders the shell for. */
  protected readonly page = HilosPages.DAEMON_HTTP_SERVER
}
