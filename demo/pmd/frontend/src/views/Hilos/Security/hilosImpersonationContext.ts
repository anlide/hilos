// The context of the framework impersonation settings admin page (@hilos/vue/admin/security
// HilosSecurityImpersonationPage, HIL-1170) bound to this project's connection,
// scopes and action lifecycle.
import { type HilosImpersonationContext } from '@hilos/core/admin/security'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework impersonation settings admin page. */
export const hilosImpersonationContext: HilosImpersonationContext = {
  connection,
  scopes,
  actions,
}
