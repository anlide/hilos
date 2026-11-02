// Bind the framework Status section to the project's existing singletons.
import { type HilosStatusContext } from '@hilos/core/admin/status'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework Status section. */
export const hilosStatusContext: HilosStatusContext = {
  connection,
  scopes,
  actions,
}
