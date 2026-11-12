// Mount the framework Tracked table page with this project's context.
import { HilosChangeLogTableHistoryPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogTableHistory() {
  return <HilosChangeLogTableHistoryPage context={hilosChangeLogContext} />
}
