// The Maintenance Nodes tab, bound to the section's shared project context.
import { HilosMaintenanceNodesPage } from '@hilos/react/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

export default function MaintenanceNodes() {
  return <HilosMaintenanceNodesPage context={hilosMaintenanceContext} />
}
