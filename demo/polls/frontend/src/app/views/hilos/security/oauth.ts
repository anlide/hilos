// The Hilos OAuth providers page (HilosPages.SECURITY_OAUTH, HIL-286): a thin
// project binding of the framework HilosSecurityOauthPage to this app's context. The
// tables, the row view-models, and the round-trips are the framework's; the project
// supplies only the context and declares its providers on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSecurityOauthPage } from '@hilos/angular'

import { hilosSecurityOauthContext } from './hilosSecurityOauthContext'

@Component({
  selector: 'app-security-oauth',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSecurityOauthPage],
  template: `<hilos-security-oauth-page [context]="context" />`,
})
export class SecurityOauth {
  protected readonly context = hilosSecurityOauthContext
}
