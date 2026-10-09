import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosProfileAgreementsHistoryPage } from '@hilos/angular'
import { hilosLegalContext } from '../hilos/legal/hilosLegalContext.js'

@Component({
  selector: 'app-profile-agreements-history',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileAgreementsHistoryPage],
  template: `<hilos-profile-agreements-history-page
    [context]="hilosLegalContext"
  />`,
})
export class ProfileAgreementsHistory {
  protected readonly hilosLegalContext = hilosLegalContext
}
