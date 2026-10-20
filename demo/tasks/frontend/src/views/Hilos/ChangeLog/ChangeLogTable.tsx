// Mount the framework Tracked table page with this project's context.
import { HilosChangeLogTablePage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogTable() {
  return <HilosChangeLogTablePage context={hilosChangeLogContext} />
}
