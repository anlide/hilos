// Mount the public file reader with the project's existing connection lifecycle.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStatusPage } from '@hilos/angular/status'

import { connection, actions } from '../../bootstrap/connection.js'

@Component({
  selector: 'app-status',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStatusPage],
  template: `<hilos-public-status-page
    [connection]="connection"
    [actions]="actions"
  />`,
})
export class Status {
  protected readonly connection = connection
  protected readonly actions = actions
}
