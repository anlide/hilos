// The context of the framework impersonation settings admin page (@hilos/vue
// HilosSecurityImpersonationPage, HIL-1170) bound to this project's connection,
// scopes and action lifecycle.
import { type HilosImpersonationContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework impersonation settings admin page. */
export const hilosImpersonationContext: HilosImpersonationContext = {
  connection,
  scopes,
  actions,
}
