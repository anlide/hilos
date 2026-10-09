import { ChangeDetectionStrategy, Component } from '@angular/core'
import {
  HilosPageHeading,
  HilosProfileSessions,
  hilosSignal,
} from '@hilos/angular'

import { profileSessionActions, profileSessions } from './profileSessions.js'

@Component({
  selector: 'app-profile-sessions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPageHeading, HilosProfileSessions],
  template: `<section>
    <hilos-page-heading
      dataId="profile-sessions-heading"
    /><hilos-profile-sessions [sessions]="sessions()" [actions]="actions" />
  </section>`,
})
export class ProfileSessions {
  protected readonly sessions = hilosSignal(profileSessions)
  protected readonly actions = profileSessionActions
}
