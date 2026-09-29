// The profile root (HilosPages.PROFILE, /profile, HIL-1169): a thin project
// binding of the framework HilosProfilePage to this app's connection, scopes and
// action lifecycle. The page, its rows and its windows are the framework's; this
// app hands it the session's name only — it has no rename of its own and keeps
// none of the lists behind the ways-in, sessions and devices summaries.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import {
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core'
import { HilosProfilePage } from '@hilos/angular'

import { actions, connection } from '../../bootstrap/connection.js'
import { currentUserName, scopes } from '../../bootstrap/session.js'

@Component({
  selector: 'app-profile',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfilePage],
  template: `<hilos-profile-page [context]="context" [binding]="binding" />`,
})
export class Profile {
  protected readonly context: HilosProfilePageContext = {
    connection,
    scopes,
    actions,
  }
  protected readonly binding: HilosProfileBinding = {
    name: currentUserName,
    rename: null,
    signInMethods: null,
    sessionCount: null,
    deviceCount: null,
  }
}
