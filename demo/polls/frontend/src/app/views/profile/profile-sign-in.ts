import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosProfileSignInPage } from '@hilos/angular'
import { hilosAuthContext } from '../../auth/hilosAuthContext.js'

@Component({
  selector: 'app-profile-sign-in',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosProfileSignInPage],
  template: `<hilos-profile-sign-in-page [context]="hilosAuthContext" />`,
})
export class ProfileSignIn {
  protected readonly hilosAuthContext = hilosAuthContext
}
