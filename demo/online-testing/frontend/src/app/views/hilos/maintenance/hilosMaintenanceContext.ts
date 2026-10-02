// Bind the framework maintenance section to the online-testing demo's connection, stores
// and action lifecycle. The framework owns the verifier circle and both actions.
import { type HilosMaintenanceContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework maintenance section. */
export const hilosMaintenanceContext: HilosMaintenanceContext = {
  connection,
  scopes,
  actions,
}
