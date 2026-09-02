// The chat's contexts for the two framework Hilos log pages that read live data:
// the rotation history (@hilos/vue HilosLogsRotationsPage) and the log viewer
// (HilosLogsViewPage). The framework owns the tables, the view-models, the
// catalog and the wording; the project supplies only where the data lives — and,
// on its backend, the registration of the hilosLogRotations table against the
// rotations page.
//
// Two contexts and not one, because the two screens need different things: the
// history reads a windowed table and therefore the scope stores, while the viewer
// reads one file through a tracked action and therefore the action lifecycle.
// Deciding what to do with a batch the rule recommends carrying off is HIL-483.
import {
  type HilosLogRotationsContext,
  type HilosLogViewerContext,
} from '@hilos/core'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

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
