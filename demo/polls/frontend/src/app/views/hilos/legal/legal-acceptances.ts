import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalAcceptancesPage } from '@hilos/angular'
import { hilosLegalContext } from './hilosLegalContext.js'
@Component({
  selector: 'app-legal-acceptances',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalAcceptancesPage],
  template: `<hilos-legal-acceptances-page [context]="context" />`,
})
export class LegalAcceptances {
  protected readonly context = hilosLegalContext
}
