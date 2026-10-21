import { type HilosCommunicationsContext } from '@hilos/core/admin/communications'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework communications pages. */
export const hilosCommunicationsContext: HilosCommunicationsContext = {
  connection,
  scopes,
  actions,
}
