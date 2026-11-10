// The context of the framework two-step verification admin page (@hilos/vue/admin/security
// HilosSecurity2faPage, HIL-494) bound to this project's connection, scopes and
// action lifecycle.
import { type HilosTwoFactorContext } from '@hilos/core/admin/security'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework two-step verification admin page. */
export const hilosTwoFactorContext: HilosTwoFactorContext = {
  connection,
  scopes,
  actions,
}
