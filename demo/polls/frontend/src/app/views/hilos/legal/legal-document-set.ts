import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalDocumentSetPage } from '@hilos/angular/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-document-set',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentSetPage],
  template: `<hilos-legal-document-set-page [context]="context" />`,
})
export class LegalDocumentSet {
  protected readonly context = hilosLegalContext
}
