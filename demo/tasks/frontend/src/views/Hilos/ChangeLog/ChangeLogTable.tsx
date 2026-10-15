// Mount the framework Tracked table page with this project's context.
import { HilosChangeLogTablePage } from '@hilos/react'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogTable() {
  return <HilosChangeLogTablePage context={hilosChangeLogContext} />
}
