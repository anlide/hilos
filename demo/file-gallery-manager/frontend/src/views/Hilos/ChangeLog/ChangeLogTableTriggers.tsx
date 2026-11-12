// Mount the framework Tracked table page with this project's context.
import { HilosChangeLogTableTriggersPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogTableTriggers() {
  return <HilosChangeLogTableTriggersPage context={hilosChangeLogContext} />
}
