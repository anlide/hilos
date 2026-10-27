// Mount the framework Change Log page with the project context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosChangeLogNoisyPage } from '@hilos/angular/admin/change-log'
import { hilosChangeLogContext } from './hilosChangeLogContext.js'
@Component({
  selector: 'app-change-log-noisy',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogNoisyPage],
  template: `<hilos-change-log-noisy-page [context]="context" />`,
})
export class ChangeLogNoisy {
  protected readonly context = hilosChangeLogContext
}
