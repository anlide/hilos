import type {
  HilosDaemonContext,
  HilosDaemonWorkersContext,
  HilosDaemonCronContext,
  HilosDaemonAgentsContext,
  HilosDaemonHttpContext,
  HilosDaemonEnvContext,
  HilosDaemonEnvMismatchContext,
} from '@hilos/core/admin/daemon'
import { connection, actions } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project binds the framework pages to its existing connection. */
export const hilosDaemonContext: HilosDaemonContext &
  HilosDaemonWorkersContext &
  HilosDaemonCronContext &
  HilosDaemonAgentsContext &
  HilosDaemonHttpContext &
  HilosDaemonEnvContext &
  HilosDaemonEnvMismatchContext = { connection, scopes, actions }
