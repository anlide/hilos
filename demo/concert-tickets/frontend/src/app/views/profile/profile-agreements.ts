// The profile agreements page (HilosPages.PROFILE_AGREEMENTS): a thin project
// binding of the framework HilosProfileAgreementsPage to this app's connection,
// scopes and action lifecycle. This project mounts no Legal administration, so
// the context is bound here rather than borrowed from an admin section.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { type HilosLegalContext } from '@hilos/core/base'
import { HilosProfileAgreementsPage } from '@hilos/angular/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

@Component({
  selector: 'app-profile-agreements',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileAgreementsPage],
  template: `<hilos-profile-agreements-page [context]="context" />`,
})
export class ProfileAgreements {
  protected readonly context: HilosLegalContext = {
    connection,
    scopes,
    actions,
  }
}
