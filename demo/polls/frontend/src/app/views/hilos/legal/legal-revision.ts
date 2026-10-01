import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalRevisionPage } from '@hilos/angular'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-revision',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalRevisionPage],
  template: `<hilos-legal-revision-page [context]="context" />`,
})
export class LegalRevision {
  protected readonly context = hilosLegalContext
}
