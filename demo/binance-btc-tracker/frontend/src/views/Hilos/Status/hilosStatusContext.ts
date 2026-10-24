// Bind the framework Status section to the project's existing singletons.
import type { HilosStatusContext } from '@hilos/core/admin/status'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** The project binding shared by both Status pages. */
export const hilosStatusContext: HilosStatusContext = {
  scopes,
  connection,
  actions,
}
