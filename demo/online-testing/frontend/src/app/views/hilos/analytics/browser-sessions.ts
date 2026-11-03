import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAnalyticsBrowserSessionsPage } from '@hilos/angular/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'
@Component({
  selector: 'app-analytics-browser-sessions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAnalyticsBrowserSessionsPage],
  template: `<hilos-analytics-browser-sessions-page [context]="context" />`,
})
export class AnalyticsBrowserSessions {
  protected readonly context = hilosAnalyticsContext
}
