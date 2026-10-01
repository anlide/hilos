import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalDocumentPage } from '@hilos/angular'
import { hilosLegalContext } from './hilosLegalContext.js'

@Component({
  selector: 'app-legal-document',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalDocumentPage],
  template: `<hilos-legal-document-page [context]="context" />`,
})
export class LegalDocument {
  protected readonly context = hilosLegalContext
}
