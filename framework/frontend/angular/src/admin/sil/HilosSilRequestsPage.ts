// HilosSilRequestsPage — the Sil Requests admin page (HilosPages.SIL_REQUESTS). A framework default: a
// thin binding of the page key to the shared admin shell HilosAdminPage, which
// resolves the heading, lead, breadcrumb, and any sub-section cards from the
// @hilos/core admin tree. Implement the page by projecting content into the
// shell's slot to replace its default body. Bootstrap classes only
// (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosPages } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'

/** The Sil Requests admin page: the framework default shell for its key. */
@Component({
  selector: 'hilos-sil-requests-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: `<hilos-admin-page [page]="page" />`,
})
export class HilosSilRequestsPage {
  /** The admin page key this default renders the shell for. */
  protected readonly page = HilosPages.SIL_REQUESTS
}
