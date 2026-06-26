// The Hilos user detail page (HilosPages.USER): a thin project binding of the
// framework HilosUserPage to this app's context. The profile selector, the inline
// rename, and the success/fail handling are the framework's; the project supplies
// only the shared HilosUsersContext (its scopes, connection, and user collection).
// Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosUserPage } from '@hilos/angular'

import { hilosUsersContext } from './hilosUsersContext'

@Component({
  selector: 'app-user',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosUserPage],
  template: `<hilos-user-page [context]="context" />`,
})
export class User {
  protected readonly context = hilosUsersContext
}
