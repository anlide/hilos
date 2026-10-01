import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalPage } from '@hilos/angular'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalPage],
  template: `<hilos-legal-page [context]="context" />`,
})
export class Legal {
  protected readonly context = hilosLegalContext
}
