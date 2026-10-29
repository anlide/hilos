import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAnalyticsPage } from '@hilos/angular/admin/analytics'
import { hilosAnalyticsContext } from './hilosAnalyticsContext.js'
@Component({
  selector: 'app-analytics',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAnalyticsPage],
  template: `<hilos-analytics-page [context]="context" />`,
})
export class Analytics {
  protected readonly context = hilosAnalyticsContext
}
