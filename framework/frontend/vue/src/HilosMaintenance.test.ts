// The standalone maintenance surface; Layout scenarios live in HilosLayout.test.
import { describe, expect, it, vi } from 'vitest'

import { registerMaintenancePassCases } from '../../test-support/maintenancePassCases.js'
import { registerMaintenanceRefusalCases } from '../../test-support/maintenanceRefusalCases.js'
import { registerMaintenanceRestoreCases } from '../../test-support/maintenanceRestoreCases.js'
import { registerMaintenanceStatusExitCases } from '../../test-support/maintenanceStatusExitCases.js'
import { mountMaintenance } from '../test/maintenanceMount.js'

registerMaintenancePassCases(
  'Vue',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceRefusalCases(
  'Vue',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceRestoreCases(
  'Vue',
  { describe, it, expect, vi },
  mountMaintenance,
)
registerMaintenanceStatusExitCases(
  'Vue',
  { describe, it, expect, vi },
  mountMaintenance,
)
