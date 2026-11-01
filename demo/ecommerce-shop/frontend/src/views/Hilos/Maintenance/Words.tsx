// The Maintenance Words tab, bound to the same project context as Overview.
import { HilosMaintenanceWordsPage } from '@hilos/react/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

export default function MaintenanceWords() {
  return <HilosMaintenanceWordsPage context={hilosMaintenanceContext} />
}
