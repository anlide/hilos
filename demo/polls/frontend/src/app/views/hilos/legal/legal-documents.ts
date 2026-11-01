import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalDocumentsPage } from '@hilos/angular/admin/legal'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-documents',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentsPage],
  template: `<hilos-legal-documents-page [context]="context" />`,
})
export class LegalDocuments {
  protected readonly context = hilosLegalContext
}
