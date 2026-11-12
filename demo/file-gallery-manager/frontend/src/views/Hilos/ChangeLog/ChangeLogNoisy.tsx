// Mount the framework Change Log page with the project context.
import { HilosChangeLogNoisyPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogNoisy() {
  return <HilosChangeLogNoisyPage context={hilosChangeLogContext} />
}
