// The PMD project's HilosSecurityOauthContext (HIL-286): binds the framework Hilos OAuth
// admin pages (@hilos/vue/admin/security HilosSecurityOauthPage / HilosSecurityOauthProviderPage)
// to this project's connection, scope stores, and action lifecycle. The framework
// owns the providers / fields / return-address tables, their view-models, and the
// set / reset round-trips; the project supplies only where the data lives — and,
// on its backend, the provider directory.
import { type HilosSecurityOauthContext } from '@hilos/core/admin/security'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework OAuth admin pages. */
export const hilosSecurityOauthContext: HilosSecurityOauthContext = {
  connection,
  scopes,
  actions,
}
