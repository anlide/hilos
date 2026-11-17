import { createHilosProfileSessionActions } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

/** Tracked actions shared by the profile session page and SDK block. */
export const profileSessionActions = createHilosProfileSessionActions({
  actions,
})
