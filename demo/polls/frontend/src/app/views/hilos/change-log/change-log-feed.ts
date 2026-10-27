// Mount the framework Change Log page with the project context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogFeedPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-feed',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogFeedPage],
  template: `<hilos-change-log-feed-page [context]="context" />`,
})
export class ChangeLogFeed {
  protected readonly context = hilosChangeLogContext
}
