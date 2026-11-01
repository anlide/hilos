// The Maintenance Circle tab, bound to the section's shared project context.
import { HilosMaintenanceCirclePage } from '@hilos/react/admin/maintenance'

import { hilosMaintenanceContext } from './hilosMaintenanceContext.js'

export default function MaintenanceCircle() {
  return <HilosMaintenanceCirclePage context={hilosMaintenanceContext} />
}
