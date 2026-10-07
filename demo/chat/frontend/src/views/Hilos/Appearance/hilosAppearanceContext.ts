import { type HilosAppearanceContext } from '@hilos/core'
import { connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** Chat's connection and page scopes for the framework Appearance view. */
export const hilosAppearanceContext: HilosAppearanceContext = {
  connection,
  scopes,
}
