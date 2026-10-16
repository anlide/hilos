import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAnalyticsPersonPage } from '@hilos/angular'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'
@Component({
  selector: 'app-analytics-person',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAnalyticsPersonPage],
  template: `<hilos-analytics-person-page [context]="context" />`,
})
export class AnalyticsPerson {
  protected readonly context = hilosAnalyticsContext
}
