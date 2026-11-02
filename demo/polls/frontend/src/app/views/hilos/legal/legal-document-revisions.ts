import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalDocumentRevisionsPage } from '@hilos/angular/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-document-revisions',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentRevisionsPage],
  template: `<hilos-legal-document-revisions-page [context]="context" />`,
})
export class LegalDocumentRevisions {
  protected readonly context = hilosLegalContext
}
