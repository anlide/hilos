// The chat's contexts for the four framework Hilos log pages that read live data:
// the stream list (@hilos/vue HilosLogsKeysPage), the worker streams
// (HilosLogsWorkersPage), the rotation history (HilosLogsRotationsPage) and the log
// viewer (HilosLogsViewPage). The framework owns the tables, the view-models, the
// catalog and the wording; the project supplies only where the data lives — and, on
// its backend, the registration of the hilosLogKeys, hilosLogWorkers and
// hilosLogRotations tables against their pages.
//
// Separate contexts and not one, because the screens need different things: the
// windowed tables read the scope stores, while the viewer reads one file through a
// tracked action and therefore the action lifecycle.
// Deciding what to do with a batch the rule recommends carrying off is HIL-483.
import {
  type HilosLogKeysContext,
  type HilosLogRotationsContext,
  type HilosLogWorkersContext,
  type HilosLogViewerContext,
} from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

/** This project's context for the framework by-key page. */
export const hilosLogKeysContext: HilosLogKeysContext = {
  connection,
  scopes,
}

/** This project's context for the framework by-worker page. */
export const hilosLogWorkersContext: HilosLogWorkersContext = {
  connection,
  scopes,
}

/** This project's context for the framework rotation-history page. */
export const hilosLogsContext: HilosLogRotationsContext = {
  connection,
  scopes,
}

/** This project's context for the framework log viewer. */
export const hilosLogViewerContext: HilosLogViewerContext = {
  connection,
  actions,
}
