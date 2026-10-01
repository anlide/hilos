// The public Terms page (HilosPages.TERMS). The page is the framework's
// (HilosTermsPage): its body is the text of the Terms revision in force, read
// from the project's legal catalog (backend/Legal/), with the reader's standing
// and the revision history. This project supplies only the introduction above
// the text.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosTermsPage } from '@hilos/angular'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

@Component({
  selector: 'app-terms',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosTermsPage],
  template: `<hilos-terms-page [context]="context">
    <p>
      This is a demonstration application provided for evaluation purposes only,
      without warranty of any kind.
    </p>
  </hilos-terms-page>`,
})
export class Terms {
  protected readonly context = { connection, scopes, actions }
}
