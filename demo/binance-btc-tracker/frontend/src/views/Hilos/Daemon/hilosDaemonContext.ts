import {
  type HilosDaemonContext,
  type HilosDaemonAgentsContext,
  type HilosDaemonCronContext,
  type HilosDaemonEnvContext,
  type HilosDaemonEnvMismatchContext,
  type HilosDaemonHttpContext,
  type HilosDaemonWorkersContext,
} from '@hilos/core/admin/daemon'
import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** The tracker demo's binding for the Daemon root. */
export const hilosDaemonContext: HilosDaemonContext = {
  connection,
  scopes,
}

/** The tracker demo's binding for the Daemon workers page. */
export const hilosDaemonWorkersContext: HilosDaemonWorkersContext = {
  connection,
  scopes,
}

/** The tracker demo's binding for the Daemon agents page. */
export const hilosDaemonAgentsContext: HilosDaemonAgentsContext = {
  connection,
  scopes,
  actions,
}

/** The tracker demo's binding for the Daemon cron page. */
export const hilosDaemonCronContext: HilosDaemonCronContext = {
  connection,
  scopes,
}

/** The tracker demo's binding for the Daemon HTTP page. */
export const hilosDaemonHttpContext: HilosDaemonHttpContext = {
  connection,
  scopes,
}

/** The tracker demo's binding for the Daemon environment mismatch page. */
export const hilosDaemonEnvMismatchContext: HilosDaemonEnvMismatchContext = {
  connection,
  scopes,
}

/** The tracker demo's binding for the Daemon environment page. */
export const hilosDaemonEnvContext: HilosDaemonEnvContext = {
  connection,
  scopes,
}
