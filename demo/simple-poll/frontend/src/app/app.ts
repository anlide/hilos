import { Component } from '@angular/core'
import { connectionStateSignal, hilosSignal } from '@hilos/angular'

import { connection } from './connection'
import { currentUserName } from './session'

// Root view. Besides the title it shows the live Connection-machine state
// through the Angular adapter — the transport slice of the conformance demo
// (docs/agents/frontend/multiframework-core.md) — and the current user
// resolved from the session scope. Real views land on top of this from
// step 7, tracking each core capability as it lands.
@Component({
  selector: 'app-root',
  template: `<main data-id="app-root">
    Hilos simple-poll (Angular)
    <span data-id="conn-state">{{ connectionState() }}</span>
    <span data-id="self-user">{{ selfName() }}</span>
  </main>`,
})
export class App {
  protected readonly connectionState = connectionStateSignal(connection)
  protected readonly selfName = hilosSignal(currentUserName)
}
