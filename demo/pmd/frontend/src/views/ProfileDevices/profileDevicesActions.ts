import { createHilosProfileDeviceActions } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** Tracked remove action shared by the profile devices page and SDK block. */
export const profileDeviceActions = createHilosProfileDeviceActions({ actions })
