import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLegalSettingsPage } from '@hilos/angular'
import { hilosLegalContext } from './hilosLegalContext.js'
@Component({
  selector: 'app-legal-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLegalSettingsPage],
  template: `<hilos-legal-settings-page [context]="context" />`,
})
export class LegalSettings {
  protected readonly context = hilosLegalContext
}
