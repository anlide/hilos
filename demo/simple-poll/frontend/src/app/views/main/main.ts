// The poll main page (PAGE_MAIN). For now it shows the identity line of the
// session; the real poll views land here later. Rendered by HilosView when the
// navigator's route is the main page.
//
// Two branches since HIL-611, because a visitor is no longer a user: with an
// account it shows the account's name from the session scope, without one the
// guest name this demo assigned. The `self-user` marker carries whichever name
// is on screen, so a test can read the line without knowing which branch it is.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { hilosSignal } from '@hilos/angular'

import { currentGuestName } from '../../bootstrap/guest'
import { currentUserId, currentUserName } from '../../bootstrap/session'

@Component({
  selector: 'app-main',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<h1 class="visually-hidden">Polls</h1>
    <p>
      @if (selfId() === null) {
        Browsing as <span data-id="self-user">{{ guestName() }}</span>
      } @else {
        Signed in as <span data-id="self-user">{{ selfName() }}</span>
      }
      <span data-id="self-user-id" hidden>{{ selfId() }}</span>
    </p>`,
})
export class Main {
  protected readonly selfName = hilosSignal(currentUserName)

  protected readonly selfId = hilosSignal(currentUserId)

  protected readonly guestName = hilosSignal(currentGuestName)
}
