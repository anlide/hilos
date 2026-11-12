// Mount the framework Tracked tables page with this project's context.
import { HilosChangeLogTablesPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogTables() {
  return <HilosChangeLogTablesPage context={hilosChangeLogContext} />
}
