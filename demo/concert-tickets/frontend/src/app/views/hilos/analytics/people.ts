import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAnalyticsPeoplePage } from '@hilos/angular/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'
@Component({
  selector: 'app-analytics-people',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAnalyticsPeoplePage],
  template: `<hilos-analytics-people-page [context]="context" />`,
})
export class AnalyticsPeople {
  protected readonly context = hilosAnalyticsContext
}
