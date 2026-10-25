import type { HilosModerationContext } from '@hilos/core/admin/moderation'
import { connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

export const hilosModerationContext: HilosModerationContext = {
  scopes,
  connection,
}
