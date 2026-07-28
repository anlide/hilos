// The chat's HilosCommunicationsContext: binds the framework Hilos communications
// pages (@hilos/vue HilosCommunicationsPage / HilosCommunicationsChannelPage) to
// this project's connection, scope stores, and action lifecycle. The framework
// owns the channels/fields tables, their view-models, and the set / reset / test
// round-trips; the project supplies only where the data lives — and, on its
// backend, the actual channel registry and its settings catalog fragment.
import { type HilosCommunicationsContext } from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework communications pages. */
export const hilosCommunicationsContext: HilosCommunicationsContext = {
  connection,
  scopes,
  actions,
}
