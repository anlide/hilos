// The Hilos impersonation settings page (HilosPages.SECURITY_IMPERSONATION,
// HIL-1170): a thin project binding of the framework
// HilosSecurityImpersonationPage to this app's impersonation context. The table,
// the row view-model, the switches and the scope's modal are the framework's;
// the project supplies only the context and registers the table on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSecurityImpersonationPage } from '@hilos/angular'

import { hilosImpersonationContext } from './hilosImpersonationContext'

@Component({
  selector: 'app-security-impersonation',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSecurityImpersonationPage],
  template: `<hilos-security-impersonation-page [context]="context" />`,
})
export class SecurityImpersonation {
  protected readonly context = hilosImpersonationContext
}
