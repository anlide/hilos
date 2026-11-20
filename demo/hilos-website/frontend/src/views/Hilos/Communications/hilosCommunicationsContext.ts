// This demo's HilosCommunicationsContext: binds the framework delivery journal
// (@hilos/vue HilosCommunicationsDeliveriesPage) — the one Communications page the
// site serves — to this project's connection, scope stores, and action lifecycle.
// The framework owns the journal table, its view-model, and the retry round-trip;
// the project supplies only where the data lives — and, on its backend, the
// delivery channel registry and the deliveries table.
import { type HilosCommunicationsContext } from '@hilos/core/admin/communications'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework communications pages. */
export const hilosCommunicationsContext: HilosCommunicationsContext = {
  connection,
  scopes,
  actions,
}
