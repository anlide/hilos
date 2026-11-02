// The Hilos logs page (HilosPages.LOGS): a thin project binding of the framework
// HilosLogsPage to this app's context. The tiles, the takeout banner, the per-node
// table window and the two empty states are the framework's; the project supplies
// the connection and page scopes used by its overview and By node table.
import { HilosLogsPage } from '@hilos/react/admin/logs'

import { hilosLogsOverviewContext } from './hilosLogsContext'

export default function LogsOverview() {
  return <HilosLogsPage context={hilosLogsOverviewContext} />
}
