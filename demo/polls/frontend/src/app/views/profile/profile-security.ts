// The profile security page (HilosPages.PROFILE_SECURITY, /profile/security,
// HIL-494): a thin project binding of the framework HilosProfileSecurityPage to
// this app's connection, scopes and action lifecycle. The section, its modals and
// its live copy are the framework's.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { type HilosSecondFactorContext } from '@hilos/core'
import { HilosProfileSecurityPage } from '@hilos/angular'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

@Component({
  selector: 'app-profile-security',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileSecurityPage],
  template: `<hilos-profile-security-page [context]="context" />`,
})
export class ProfileSecurity {
  protected readonly context: HilosSecondFactorContext = {
    connection,
    scopes,
    actions,
  }
}
