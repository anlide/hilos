// The polls demo's contexts for the six framework Hilos log pages that read live
// data: the section overview (@hilos/angular HilosLogsPage), the stream list
// (HilosLogsKeysPage), the worker streams (HilosLogsWorkersPage), the rotation
// history (HilosLogsRotationsPage), the logging modes (HilosLogsSettingsPage) and
// the log viewer (HilosLogsViewPage). The framework owns the tables, the
// view-models, the catalog and the wording; the project supplies only where the
// data lives — and, on its backend, the registration of the hilosLogNodes,
// hilosLogKeys, hilosLogWorkers and hilosLogRotations tables against their pages.
//
// The overview and the other windowed tables need the scope stores. The viewer
// and logging modes need the action lifecycle; rotation history needs both to
// show its window and confirm recommended batches as carried off (HIL-483).
import {
  type HilosLogKeysContext,
  type HilosLogRotationsContext,
  type HilosLogViewerContext,
  type HilosLogWorkersContext,
  type HilosLogNodesContext,
} from '@hilos/core/admin/logs'
import { type HilosSettingPresetsContext } from '@hilos/core/admin/settings'

import { actions, connection } from '../../../bootstrap/connection.js'
import { scopes } from '../../../bootstrap/session.js'

/** This project's context for the framework section overview. */
export const hilosLogsOverviewContext: HilosLogNodesContext = {
  connection,
  scopes,
}

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
  actions,
}

/** This project's context for the framework logging-mode page. */
export const hilosLogSettingsContext: HilosSettingPresetsContext = {
  connection,
  actions,
}

/** This project's context for the framework log viewer. */
export const hilosLogViewerContext: HilosLogViewerContext = {
  connection,
  actions,
}
