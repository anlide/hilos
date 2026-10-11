import {
  type HilosDaemonAgentsContext,
  type HilosDaemonWorkersContext,
} from '@hilos/core'
import { connection } from '../../../bootstrap/connection.js'
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
}
