// The poll main page (PAGE_MAIN). For now it shows the session's current user
// resolved from the session scope; the real poll views land here later.
// Rendered by HilosView when the navigator's route is the main page.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { hilosSignal } from '@hilos/angular'

import { currentUserId, currentUserName } from '../../bootstrap/session'

@Component({
  selector: 'app-main',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<h1 class="visually-hidden">Polls</h1>
    <p>
      Signed in as <span data-id="self-user">{{ selfName() }}</span>
      <span data-id="self-user-id" hidden>{{ selfId() }}</span>
    </p>`,
})
export class Main {
  protected readonly selfName = hilosSignal(currentUserName)

  protected readonly selfId = hilosSignal(currentUserId)
}
