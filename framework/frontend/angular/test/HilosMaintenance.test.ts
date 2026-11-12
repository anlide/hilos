// The standalone maintenance surface; HilosMaintenancePage is the admin page.
import { describe, expect, it, vi } from 'vitest'

import { registerMaintenancePassCases } from '../../test-support/maintenancePassCases.js'
import { registerMaintenanceRefusalCases } from '../../test-support/maintenanceRefusalCases.js'
import { registerMaintenanceRestoreCases } from '../../test-support/maintenanceRestoreCases.js'
import { registerMaintenanceStatusExitCases } from '../../test-support/maintenanceStatusExitCases.js'
import { mountMaintenance } from './maintenanceMount.js'

registerMaintenancePassCases(
  'Angular',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceRefusalCases(
  'Angular',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceRestoreCases(
  'Angular',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceStatusExitCases(
  'Angular',
  { describe, it, expect, vi },
  mountMaintenance,
)
