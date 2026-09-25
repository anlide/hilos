// The chat's HilosMaintenanceContext: binds the framework Hilos maintenance section
// (@hilos/vue HilosMaintenancePage) to this project's connection and scope stores.
// The framework owns the circle table, the row view-model, and the live online mark;
// the project supplies only where the data lives (the circle is served on its backend
// by the hilos index agent).
import { type HilosMaintenanceContext } from '@hilos/core'

import { connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework maintenance section. */
export const hilosMaintenanceContext: HilosMaintenanceContext = {
  connection,
  scopes,
}
