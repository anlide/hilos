// The Hilos OAuth provider page (HilosPages.SECURITY_OAUTH_PROVIDER, HIL-286): a
// thin project binding of the framework HilosSecurityOauthProviderPage to this app's
// context. The fields table, the recipe, and the set / reset round-trips are the
// framework's; the project supplies only the shared HilosSecurityOauthContext.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSecurityOauthProviderPage } from '@hilos/angular'

import { hilosSecurityOauthContext } from './hilosSecurityOauthContext'

@Component({
  selector: 'app-security-oauth-provider',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSecurityOauthProviderPage],
  template: `<hilos-security-oauth-provider-page [context]="context" />`,
})
export class SecurityOauthProvider {
  protected readonly context = hilosSecurityOauthContext
}
