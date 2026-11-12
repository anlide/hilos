// The standalone maintenance surface; Layout scenarios live in HilosLayout.test.
import { describe, expect, it, vi } from 'vitest'

import { registerMaintenancePassCases } from '../../test-support/maintenancePassCases.js'
import { registerMaintenanceRefusalCases } from '../../test-support/maintenanceRefusalCases.js'
import { registerMaintenanceRestoreCases } from '../../test-support/maintenanceRestoreCases.js'
import { registerMaintenanceStatusExitCases } from '../../test-support/maintenanceStatusExitCases.js'
import { mountMaintenance } from './maintenanceMount.js'

registerMaintenancePassCases(
  'React',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceRefusalCases(
  'React',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceRestoreCases(
  'React',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceStatusExitCases(
  'React',
  { describe, it, expect, vi },
  mountMaintenance,
)
