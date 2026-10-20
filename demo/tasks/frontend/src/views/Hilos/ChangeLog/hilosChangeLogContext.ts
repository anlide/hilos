// The tasks demo binds the framework Change Log section to its connection, page
// scope and action lifecycle. The framework owns the feed, the tables
// viewport, the history and the receipt reader.
import { type HilosChangeLogReceiptContext } from '@hilos/core/admin/change-log'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the three framework Change Log pages. */
export const hilosChangeLogContext: HilosChangeLogReceiptContext = {
  connection,
  scopes,
  actions,
}
