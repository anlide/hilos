// HilosLogsSettingsPage — the framework Hilos logging-mode page
// (HilosPages.LOGS_SETTINGS): the Logs section's own settings surface, where the five
// keys that decide how loud the logs are come as three named modes instead. The
// layout, the two gestures, the confirmation and the states are the common
// setting-presets screen's; everything this page adds is which section it is — its
// page key, its signal and its vocabulary. A project mounts it by passing its
// HilosSettingPresetsContext. Bootstrap classes only (styling-rules.md).
import {
  HilosPages,
  LOG_SETTINGS_SIGNAL,
  hilosLogSettingsVocabulary,
} from '@hilos/core'
import type { HilosSettingPresetsContext } from '@hilos/core'

import { HilosSettingPresetsPage } from '../settings/HilosSettingPresetsPage.js'

/** Props for {@link HilosLogsSettingsPage}. */
export interface HilosLogsSettingsPageProps {
  /** The project context: the connection the frames arrive on and the action lifecycle. */
  context: HilosSettingPresetsContext
}

/**
 * The Logs section's logging modes: the common presets screen with this section's
 * key, signal and words.
 *
 * @param props The project context (the connection and the action lifecycle).
 */
export function HilosLogsSettingsPage({ context }: HilosLogsSettingsPageProps) {
  return (
    <HilosSettingPresetsPage
      page={HilosPages.LOGS_SETTINGS}
      context={context}
      signal={LOG_SETTINGS_SIGNAL}
      vocabulary={hilosLogSettingsVocabulary}
    />
  )
}
