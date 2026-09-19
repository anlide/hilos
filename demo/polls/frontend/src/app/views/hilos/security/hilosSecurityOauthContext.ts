// The polls demo's HilosSecurityOauthContext (HIL-286): binds the framework Hilos
// OAuth admin pages (@hilos/angular HilosSecurityOauthPage /
// HilosSecurityOauthProviderPage) to this project's connection, scope stores, and
// action lifecycle. The framework owns the providers / fields / return-address
// tables, their view-models, and the set / reset round-trips; the project supplies
// only where the data lives — and, on its backend, the provider directory. Shared by
// the list and provider wrappers (HilosPages.SECURITY_OAUTH / SECURITY_OAUTH_PROVIDER).
import { type HilosSecurityOauthContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework OAuth admin pages. */
export const hilosSecurityOauthContext: HilosSecurityOauthContext = {
  connection,
  scopes,
  actions,
}
