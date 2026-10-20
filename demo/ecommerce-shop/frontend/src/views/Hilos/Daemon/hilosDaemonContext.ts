import type {
  HilosDaemonContext,
  HilosDaemonWorkersContext,
  HilosDaemonCronContext,
  HilosDaemonAgentsContext,
  HilosDaemonHttpContext,
  HilosDaemonEnvContext,
  HilosDaemonEnvMismatchContext,
} from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** The ecommerce-shop demo binds the framework daemon pages to its existing connection. */
export const hilosDaemonContext: HilosDaemonContext &
  HilosDaemonWorkersContext &
  HilosDaemonCronContext &
  HilosDaemonAgentsContext &
  HilosDaemonHttpContext &
  HilosDaemonEnvContext &
  HilosDaemonEnvMismatchContext = { connection, scopes, actions }
