// The Hilos page of operations that ask for confirmation
// (HilosPages.SECURITY_STEP_UP, HIL-1204), a child of two-factor: a thin project
// binding of the framework HilosSecurityStepUpPage to this app's two-factor
// context. The table, the row view-model and the switch are the framework's; the
// project supplies only the context and registers the table on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSecurityStepUpPage } from '@hilos/angular'

import { hilosTwoFactorContext } from './hilosTwoFactorContext'

@Component({
  selector: 'app-security-step-up',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSecurityStepUpPage],
  template: `<hilos-security-step-up-page [context]="context" />`,
})
export class SecurityStepUp {
  protected readonly context = hilosTwoFactorContext
}
