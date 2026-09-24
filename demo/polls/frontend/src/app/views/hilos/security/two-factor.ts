// The Hilos two-step verification page (HilosPages.SECURITY_2FA, HIL-494): a thin
// project binding of the framework HilosSecurity2faPage to this app's context.
// The table, the row view-model and the edit modal are the framework's; the
// project supplies only the context and registers the table on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSecurity2faPage } from '@hilos/angular'

import { hilosTwoFactorContext } from './hilosTwoFactorContext'

@Component({
  selector: 'app-security-two-factor',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSecurity2faPage],
  template: `<hilos-security2fa-page [context]="context" />`,
})
export class SecurityTwoFactor {
  protected readonly context = hilosTwoFactorContext
}
