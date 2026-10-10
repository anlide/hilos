// Chat binds the framework Change Log page to its connection and page scope.
import { type HilosChangeLogContext } from '@hilos/core'

import { connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework Change Log page. */
export const hilosChangeLogContext: HilosChangeLogContext = {
  connection,
  scopes,
}
