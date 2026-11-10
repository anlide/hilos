import { type HilosProfilePageContext } from '@hilos/core/base'
import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

export const hilosProfileNotificationsContext: HilosProfilePageContext = {
  connection,
  scopes,
  actions,
}
