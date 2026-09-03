// The Hilos by-worker page (HilosPages.LOGS_WORKERS): a thin project binding of the
// framework HilosLogsWorkersPage to this app's context. The stream table, the row
// view-model, the filters, the header and the four empty states are the framework's;
// the project supplies only the context (the same connection and scope stores the
// other Hilos pages use) and registers the hilosLogWorkers table on its backend.
import { HilosLogsWorkersPage } from '@hilos/react'

import { hilosLogWorkersContext } from './hilosLogsContext'

export default function LogsWorkers() {
  return <HilosLogsWorkersPage context={hilosLogWorkersContext} />
}
