// The chat's HilosBackupsContext: binds the framework Hilos backup page
// (@hilos/vue HilosBackupPage) to this project's connection and scope stores. The
// framework owns the table, the row view-model, and the live-row behavior; the
// project supplies only where the data lives (the backup index is produced on its
// backend by the monopoly backup agent).
import { type HilosBackupsContext } from '@hilos/core'

import { connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework backup page. */
export const hilosBackupsContext: HilosBackupsContext = {
  connection,
  scopes,
}
