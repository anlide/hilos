// The context of the framework two-step verification admin page (@hilos/angular
// HilosSecurity2faPage, HIL-494) bound to this project's connection, scopes and
// action lifecycle.
import { type HilosTwoFactorContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework two-step verification admin page. */
export const hilosTwoFactorContext: HilosTwoFactorContext = {
  connection,
  scopes,
  actions,
}
