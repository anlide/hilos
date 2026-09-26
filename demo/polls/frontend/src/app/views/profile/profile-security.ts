// The profile security page (HilosPages.PROFILE_SECURITY, /profile/security,
// HIL-494): a thin project binding of the framework HilosProfileSecurityPage to
// this app's connection, scopes and action lifecycle. The section, its modals and
// its live copy are the framework's. The account deletion's danger zone sits at
// its bottom (HIL-302): this app's profile has no other page to carry it.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { type HilosSecondFactorContext } from '@hilos/core'
import {
  HilosAccountDeletion,
  HilosProfileSecurityPage,
} from '@hilos/angular'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

@Component({
  selector: 'app-profile-security',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileSecurityPage, HilosAccountDeletion],
  template: `
    <hilos-profile-security-page [context]="context" />
    <hilos-account-deletion [context]="context" />
  `,
})
export class ProfileSecurity {
  protected readonly context: HilosSecondFactorContext = {
    connection,
    scopes,
    actions,
  }
}
