// The chat's contexts for the six framework Hilos log pages that read live data:
// the section overview (@hilos/vue HilosLogsPage), the stream list
// (HilosLogsKeysPage), the worker streams (HilosLogsWorkersPage), the rotation
// history (HilosLogsRotationsPage), the logging modes (HilosLogsSettingsPage) and
// the log viewer (HilosLogsViewPage). The
// framework owns the tables, the view-models, the catalog and the wording; the
// project supplies only where the data lives — and, on its backend, the registration
// of the hilosLogNodes, hilosLogKeys, hilosLogWorkers and hilosLogRotations tables
// against their pages.
//
// Separate contexts because the screens need different things: the overview and
// other windowed tables read the scope stores; the viewer reads one file through
// a tracked action; the logging modes read one frame and send one action without
// scope stores. The rotation history needs both a windowed table and the action
// lifecycle, because a recommended batch is confirmed as carried off from it
// (HIL-483).
import {
  type HilosLogKeysContext,
  type HilosLogNodesContext,
  type HilosLogRotationsContext,
  type HilosLogWorkersContext,
  type HilosLogViewerContext,
} from '@hilos/core/admin/logs'
import { type HilosSettingPresetsContext } from '@hilos/core/admin/settings'

import { actions, connection } from '../../../bootstrap/connection'
import { scopes } from '../../../bootstrap/session'

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
