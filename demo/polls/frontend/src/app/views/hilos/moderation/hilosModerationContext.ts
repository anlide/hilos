import { type HilosModerationRulesContext } from '@hilos/core/admin/moderation'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework moderation pages. */
export const hilosModerationContext: HilosModerationRulesContext = {
  connection,
  scopes,
  actions,
}
