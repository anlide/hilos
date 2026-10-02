// Mount the framework maintenance section with this project's context.
import { HilosMaintenancePage } from '@hilos/react'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

export default function Maintenance() {
  return <HilosMaintenancePage context={hilosMaintenanceContext} />
}
