// The standalone maintenance surface; Layout scenarios live in HilosLayout.test.
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { formatRestorePhaseLine } from '@hilos/core/base'

import {
  FROZEN,
  maintenanceFeed,
  restoreFrame,
} from '../../test-support/maintenanceFixtures.js'
import { registerMaintenancePassCases } from '../../test-support/maintenancePassCases.js'
import { registerMaintenanceRefusalCases } from '../../test-support/maintenanceRefusalCases.js'
import { registerMaintenanceRestoreCases } from '../../test-support/maintenanceRestoreCases.js'
import { registerMaintenanceStatusExitCases } from '../../test-support/maintenanceStatusExitCases.js'
import { mountMaintenance } from '../test/maintenanceMount.js'
import HilosMaintenance from './HilosMaintenance.vue'

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

// A Vue prop replacement keeps this mounted surface and moves its restore feed.
it('follows restore progress only from the replacement Vue connection', async () => {
  const previous = maintenanceFeed(FROZEN)
  const current = maintenanceFeed(FROZEN)
  const view = mount(HilosMaintenance, {
    props: {
      status: FROZEN,
      connection: previous.connection,
      adminSurface: false,
    },
  })
  try {
    await view.setProps({ connection: current.connection })
    const frame = restoreFrame()
    current.pushRestore(frame)
    await nextTick()
    expect(view.get('[data-id="maintenance-restore-phase"]').text()).toBe(
      formatRestorePhaseLine(frame),
    )

    previous.pushRestore(restoreFrame({ phase: 'done' }))
    await nextTick()
    expect(view.get('[data-id="maintenance-restore-phase"]').text()).toBe(
      formatRestorePhaseLine(frame),
    )
  } finally {
    view.unmount()
    previous.dispose()
    current.dispose()
  }
})
