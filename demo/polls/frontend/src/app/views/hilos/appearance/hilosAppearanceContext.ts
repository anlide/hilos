// The polls demo's HilosAppearanceContext: binds the framework Appearance page
// (@hilos/angular HilosAppearancePage) to this project's connection, scope
// stores and action lifecycle. The framework owns the table, the windows and the
// writes; the settings themselves are the framework's theme catalog.
import { type HilosAppearanceContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework Appearance page. */
export const hilosAppearanceContext: HilosAppearanceContext = {
  connection,
  scopes,
  actions,
}
