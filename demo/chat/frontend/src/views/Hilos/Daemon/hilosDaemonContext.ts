import {
  type HilosDaemonAgentsContext,
  type HilosDaemonCronContext,
  type HilosDaemonHttpContext,
  type HilosDaemonWorkersContext,
} from '@hilos/core'
import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** The chat demo's binding for the Daemon workers page. */
export const hilosDaemonWorkersContext: HilosDaemonWorkersContext = {
  connection,
  scopes,
}

/** The chat demo's binding for the Daemon agents page. */
export const hilosDaemonAgentsContext: HilosDaemonAgentsContext = {
  connection,
  scopes,
  actions,
}

/** The chat demo's binding for the Daemon cron page. */
export const hilosDaemonCronContext: HilosDaemonCronContext = {
  connection,
  scopes,
}

/** The chat demo's binding for the Daemon HTTP page. */
export const hilosDaemonHttpContext: HilosDaemonHttpContext = {
  connection,
  scopes,
}
