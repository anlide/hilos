import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosProfileAgreementsPage } from '@hilos/angular'
import { hilosLegalContext } from '../hilos/legal/hilosLegalContext.js'

@Component({
  selector: 'app-profile-agreements',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileAgreementsPage],
  template: `<hilos-profile-agreements-page [context]="hilosLegalContext" />`,
})
export class ProfileAgreements {
  protected readonly hilosLegalContext = hilosLegalContext
}
