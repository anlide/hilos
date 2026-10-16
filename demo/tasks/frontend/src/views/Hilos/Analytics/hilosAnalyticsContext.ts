import { type HilosAnalyticsContext } from '@hilos/core'
import { connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** Analytics bound to this project's connection and page scopes. */
export const hilosAnalyticsContext: HilosAnalyticsContext = {
  connection,
  scopes,
}
