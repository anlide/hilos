import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalRevisionTextPage } from '@hilos/angular/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-revision-text',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalRevisionTextPage],
  template: `<hilos-legal-revision-text-page [context]="context" />`,
})
export class LegalRevisionText {
  protected readonly context = hilosLegalContext
}
