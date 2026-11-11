// Mount the framework Change Log dashboard (HilosPages.CHANGE_LOG) with this
// project's context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogPage],
  template: `<hilos-change-log-page [context]="context" />`,
})
export class ChangeLog {
  protected readonly context = hilosChangeLogContext
}
