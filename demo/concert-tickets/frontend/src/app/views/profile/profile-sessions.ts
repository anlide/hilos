import { ChangeDetectionStrategy, Component } from '@angular/core'
import {
  HilosProfileHead,
  HilosProfileSessions,
  hilosSignal,
} from '@hilos/angular/base'

import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

import { profileSessionActions, profileSessions } from './profileSessions.js'

@Component({
  selector: 'app-profile-sessions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileHead, HilosProfileSessions],
  template: `<section>
    <hilos-profile-head [context]="context" /><hilos-profile-sessions
      [sessions]="sessions()"
      [actions]="actions"
    />
  </section>`,
})
export class ProfileSessions {
  protected readonly context = { connection, scopes, actions }
  protected readonly sessions = hilosSignal(profileSessions)
  protected readonly actions = profileSessionActions
}
