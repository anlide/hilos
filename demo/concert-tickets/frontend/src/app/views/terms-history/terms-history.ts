import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalHistoryPage } from '@hilos/angular/base'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { router } from '../../pages/routes.js'

@Component({
  selector: 'app-terms-history',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalHistoryPage],
  template: `<hilos-legal-history-page
    [context]="context"
    document="terms"
    [navigation]="navigation"
  />`,
})
export class TermsHistory {
  protected readonly context = { connection, scopes, actions }
  protected readonly navigation = hilosPublicLegalNavigation(
    HilosPages.TERMS_HISTORY,
    (page, params) => router.path(page, params),
  )
}
