import type { HilosModerationRulesContext } from '@hilos/core/admin/moderation'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

export const hilosModerationContext: HilosModerationRulesContext = {
  connection,
  scopes,
  actions,
}
