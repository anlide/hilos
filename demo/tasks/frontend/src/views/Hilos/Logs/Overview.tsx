// The Hilos logs page (HilosPages.LOGS): a thin project binding of the framework
// HilosLogsPage to this app's context. The tiles, the takeout banner, the per-node
// table and the two empty states are the framework's; the project supplies only the
// context, and its backend registers nothing further — the overview page and its
// agent are already there and it owns no table.
import { HilosLogsPage } from '@hilos/react'

import { hilosLogsOverviewContext } from './hilosLogsContext'

export default function LogsOverview() {
  return <HilosLogsPage context={hilosLogsOverviewContext} />
}
