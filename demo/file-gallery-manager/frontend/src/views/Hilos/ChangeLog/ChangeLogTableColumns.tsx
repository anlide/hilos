// Mount the framework Tracked table page with this project's context.
import { HilosChangeLogTableColumnsPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogTableColumns() {
  return <HilosChangeLogTableColumnsPage context={hilosChangeLogContext} />
}
