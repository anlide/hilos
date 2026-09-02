// The chat's HilosLogRotationsContext: binds the framework Hilos rotation-history
// page (@hilos/vue HilosLogsRotationsPage) to this project's connection and scope
// stores. The framework owns the history table, its view-model, the header and the
// wording; the project supplies only where the data lives — and, on its backend,
// the registration of the hilosLogRotations table against the rotations page.
//
// No action lifecycle here, unlike the communications context: this screen has no
// action at all. Deciding what to do with a batch the rule recommends carrying off
// is HIL-483.
import { type HilosLogRotationsContext } from '@hilos/core'

import { connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework log pages. */
export const hilosLogsContext: HilosLogRotationsContext = {
  connection,
  scopes,
}
