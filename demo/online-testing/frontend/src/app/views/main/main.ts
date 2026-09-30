// The online-testing main page (PAGE_MAIN). For now it says who is looking and
// nothing more; the list of tests this page becomes is drawn in the demo's
// mockup and arrives with leaves of its own. Rendered by HilosView when the
// navigator's route is the main page.
//
// Two branches, because a visitor is not a user: with an account the line names
// the account from the session scope, without one it says the visit is
// anonymous — the demo hands a guest no name, so the `self-user` marker is
// absent rather than empty.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { hilosSignal } from '@hilos/angular'

import { currentUserId, currentUserName } from '../../bootstrap/session.js'

@Component({
  selector: 'app-main',
  changeDetection: ChangeDetectionStrategy.OnPush,
  // What decides the branch is whether the session names a user, never whether
  // a name string came out empty: the id and the name are read off one
  // session-scope ref, so an absent ref gives a null id and an empty name together.
  template: `<h1 class="visually-hidden">Tests</h1>
    <p>
      @if (selfId() === null) {
        <span data-id="self-anonymous">Browsing anonymously</span>
      } @else {
        Signed in as <span data-id="self-user">{{ selfName() }}</span>
        <span data-id="self-user-id" hidden>{{ selfId() }}</span>
      }
    </p>`,
})
export class Main {
  protected readonly selfName = hilosSignal(currentUserName)

  protected readonly selfId = hilosSignal(currentUserId)
}
