// Mount the framework Change Log dashboard with this project's context.
import { HilosChangeLogPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLog() {
  return <HilosChangeLogPage context={hilosChangeLogContext} />
}
