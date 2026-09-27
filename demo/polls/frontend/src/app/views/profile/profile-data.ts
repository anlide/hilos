import { ChangeDetectionStrategy, Component } from '@angular/core'
import { type HilosDataExportContext } from '@hilos/core'
import { HilosProfileDataPage } from '@hilos/angular'
import { actions, connection } from '../../bootstrap/connection.js'
import { scopes } from '../../bootstrap/session.js'

@Component({
  selector: 'app-profile-data',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileDataPage],
  template: `<hilos-profile-data-page [context]="context" />`,
})
export class ProfileData {
  protected readonly context: HilosDataExportContext = {
    connection,
    scopes,
    actions,
  }
}
