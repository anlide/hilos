import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalHistoryPage } from '@hilos/angular/base'
import { HilosPages, hilosPublicLegalNavigation } from '@hilos/core/base'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'
import { router } from '../../pages/routes.js'

@Component({
  selector: 'app-privacy-history',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalHistoryPage],
  template: `<hilos-legal-history-page
    [context]="context"
    document="privacy"
    [navigation]="navigation"
  />`,
})
export class PrivacyHistory {
  protected readonly context = { connection, scopes, actions }
  protected readonly navigation = hilosPublicLegalNavigation(
    HilosPages.PRIVACY_HISTORY,
    (page, params) => router.path(page, params),
  )
}
