import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAnalyticsBrowserSessionPage } from '@hilos/angular'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'
@Component({
  selector: 'app-analytics-browser-session',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAnalyticsBrowserSessionPage],
  template: `<hilos-analytics-browser-session-page [context]="context" />`,
})
export class AnalyticsBrowserSession {
  protected readonly context = hilosAnalyticsContext
}
