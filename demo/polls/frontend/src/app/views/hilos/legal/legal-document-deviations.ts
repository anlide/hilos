import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalDocumentDeviationsPage } from '@hilos/angular/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-document-deviations',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentDeviationsPage],
  template: `<hilos-legal-document-deviations-page [context]="context" />`,
})
export class LegalDocumentDeviations {
  protected readonly context = hilosLegalContext
}
