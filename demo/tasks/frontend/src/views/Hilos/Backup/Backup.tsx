// The Hilos backup page (HilosPages.BACKUP): a thin project binding of the
// framework HilosBackupPage to this app's context. The backup list table, the
// verifier circle, the row view-model, the live in-progress row and the reopen
// block are the framework's; the project supplies only the context (its scope
// stores + live connection) and produces the backup index on its backend.
// Bootstrap classes only (styling-rules.md).
import { HilosBackupPage } from '@hilos/react'

import { hilosBackupsContext } from './hilosBackupsContext'

export default function Backup() {
  return <HilosBackupPage context={hilosBackupsContext} />
}
