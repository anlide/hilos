// The Hilos log viewer (HilosPages.LOGS_VIEW): a thin project binding of the
// framework HilosLogsViewPage to this app's context. The catalog of readable
// sources, the address that names one file, the reading of its lines and every
// empty state are the framework's; the project supplies only the connection the
// catalog arrives on and the action lifecycle the reads go over.
import { HilosLogsViewPage } from '@hilos/react'

import { hilosLogViewerContext } from './hilosLogsContext'

export default function LogsView() {
  return <HilosLogsViewPage context={hilosLogViewerContext} />
}
