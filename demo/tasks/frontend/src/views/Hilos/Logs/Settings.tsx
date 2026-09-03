// The Hilos logging-mode page (HilosPages.LOGS_SETTINGS): a thin project binding of
// the framework HilosLogsSettingsPage to this app's context. The three modes, what
// each of them writes, the drift from the chosen one and the confirmation before
// overwriting hand-made edits are the framework's; the project supplies only the
// context (the same connection and action lifecycle the other Hilos pages use) and
// registers the LogsSettingsPage on its backend.
import { HilosLogsSettingsPage } from '@hilos/react'

import { hilosLogSettingsContext } from './hilosLogsContext'

export default function LogsSettings() {
  return <HilosLogsSettingsPage context={hilosLogSettingsContext} />
}
