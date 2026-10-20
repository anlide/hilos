import type {
  HilosDaemonContext,
  HilosDaemonWorkersContext,
  HilosDaemonAgentsContext,
  HilosDaemonCronContext,
  HilosDaemonHttpContext,
  HilosDaemonEnvContext,
  HilosDaemonEnvMismatchContext,
} from '@hilos/core/admin/daemon'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** The root reads the existing whole page answer. */
export const hilosDaemonContext: HilosDaemonContext = { connection, scopes }
/** Workers read the selected node's server window. */
export const hilosDaemonWorkersContext: HilosDaemonWorkersContext = {
  connection,
  scopes,
}
/** Agents also bind the existing tracked restart action. */
export const hilosDaemonAgentsContext: HilosDaemonAgentsContext = {
  connection,
  scopes,
  actions,
}

/** Cron reads the selected node's existing schedule window. */
export const hilosDaemonCronContext: HilosDaemonCronContext = {
  connection,
  scopes,
}
/** HTTP reads the listener summary and routes from the existing page. */
export const hilosDaemonHttpContext: HilosDaemonHttpContext = {
  connection,
  scopes,
}
/** Environment reads the node's masked keys and orphan windows. */
export const hilosDaemonEnvContext: HilosDaemonEnvContext = {
  connection,
  scopes,
}
/** Mismatch reads the cluster comparison and its two verdict windows. */
export const hilosDaemonEnvMismatchContext: HilosDaemonEnvMismatchContext = {
  connection,
  scopes,
}
