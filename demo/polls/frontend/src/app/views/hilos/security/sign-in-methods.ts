// The Hilos sign-in methods page (HilosPages.SECURITY_SIGN_IN_METHODS, HIL-427): a
// thin project binding of the framework HilosSecuritySignInMethodsPage to this
// app's context. The table, the row view-model and the switch are the framework's;
// the project supplies only the context and declares its methods on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSecuritySignInMethodsPage } from '@hilos/angular'

import { hilosSignInMethodsContext } from './hilosSignInMethodsContext'

@Component({
  selector: 'app-security-sign-in-methods',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSecuritySignInMethodsPage],
  template: `<hilos-security-sign-in-methods-page [context]="context" />`,
})
export class SecuritySignInMethods {
  protected readonly context = hilosSignInMethodsContext
}
