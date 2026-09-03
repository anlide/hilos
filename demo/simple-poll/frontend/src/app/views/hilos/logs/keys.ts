// The Hilos by-key page (HilosPages.LOGS_KEYS): a thin project binding of the
// framework HilosLogsKeysPage to this app's context. The windowed stream table, the
// node and class filters, the row view-model and the four empty states are the
// framework's; the project supplies only the context and registers the hilosLogKeys
// table against this page on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLogsKeysPage } from '@hilos/angular'

import { hilosLogKeysContext } from './hilosLogsContext'

@Component({
  selector: 'app-log-keys',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLogsKeysPage],
  template: `<hilos-logs-keys-page [context]="context" />`,
})
export class LogKeys {
  protected readonly context = hilosLogKeysContext
}
