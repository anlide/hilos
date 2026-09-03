// The Hilos rotation-history page (HilosPages.LOGS_ROTATIONS): a thin project
// binding of the framework HilosLogsRotationsPage to this app's context. The history
// table, the row view-model, the filters, the header and the four empty states are
// the framework's; the project supplies only the context (the same connection and
// scope stores the other Hilos pages use) and registers the rotations table on its
// backend.
import { HilosLogsRotationsPage } from '@hilos/react'

import { hilosLogsContext } from './hilosLogsContext'

export default function LogsRotations() {
  return <HilosLogsRotationsPage context={hilosLogsContext} />
}
