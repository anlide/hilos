// HilosLogsSettingsPage — the framework Hilos logging-mode page
// (HilosPages.LOGS_SETTINGS): the Logs section's own settings surface, where the five
// keys that decide how loud the logs are come as three named modes instead. The
// layout, the two gestures, the confirmation and the states are the common
// setting-presets screen's; everything this page adds is which section it is — its
// page key, its signal and its vocabulary. A project mounts it by passing its
// HilosSettingPresetsContext. Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import {
  HilosPages,
  LOG_SETTINGS_SIGNAL,
  hilosLogSettingsVocabulary,
} from '@hilos/core'
import type { HilosSettingPresetsContext } from '@hilos/core'

import { HilosSettingPresetsPage } from '../settings/HilosSettingPresetsPage.js'

/** The framework logging-mode page: the presets screen with the logs section's words. */
@Component({
  selector: 'hilos-logs-settings-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSettingPresetsPage],
  template: `
    <hilos-setting-presets-page
      [page]="page"
      [context]="context()"
      [signal]="signal"
      [vocabulary]="vocabulary"
    />
  `,
})
export class HilosLogsSettingsPage {
  /** The project context: the connection the frames arrive on and the action lifecycle. */
  readonly context = input.required<HilosSettingPresetsContext>()

  protected readonly page = HilosPages.LOGS_SETTINGS
  protected readonly signal = LOG_SETTINGS_SIGNAL
  protected readonly vocabulary = hilosLogSettingsVocabulary
}
