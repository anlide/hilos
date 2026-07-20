// The chat's HilosBackupsContext: binds the framework Hilos backup page
// (@hilos/vue HilosBackupPage) to this project's connection, scope stores, and
// action lifecycle. The framework owns the table, the row view-model, the
// live-row behavior, and the create / delete / keep round-trips; the project
// supplies only where the data lives (the backup index is produced on its backend
// by the monopoly backup agent).
import { type HilosBackupsContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework backup page. */
export const hilosBackupsContext: HilosBackupsContext = {
  connection,
  scopes,
  actions,
}
