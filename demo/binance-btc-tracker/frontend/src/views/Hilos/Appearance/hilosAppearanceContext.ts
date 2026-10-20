import { type HilosAppearanceContext } from '@hilos/core/admin/appearance'
import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** Tracker connection, page scopes and writes for the framework Appearance view. */
export const hilosAppearanceContext: HilosAppearanceContext = {
  connection,
  scopes,
  actions,
}
