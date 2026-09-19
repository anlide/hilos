// The chat's HilosSignInMethodsContext (HIL-427): binds the framework sign-in
// methods admin page (@hilos/vue HilosSecuritySignInMethodsPage) to this project's
// connection, scope stores, and action lifecycle. The framework owns the table,
// its view-model, the live enabled set and the switch; the project supplies only
// where the data lives — and, on its backend, the method directory.
import { type HilosSignInMethodsContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework sign-in methods admin page. */
export const hilosSignInMethodsContext: HilosSignInMethodsContext = {
  connection,
  scopes,
  actions,
}
