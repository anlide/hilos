import { Component } from '@angular/core'
import { HilosModal } from '@hilos/angular/base'
import type { HilosTrackedAction } from '@hilos/angular/base'

@Component({
  selector: 'test-frame',
  imports: [HilosModal],
  template:
    '<hilos-modal><ng-content select="[dialogAction]" /><ng-content /></hilos-modal>',
})
export class DialogFrame {}

@Component({
  selector: 'test-host',
  imports: [DialogFrame],
  template: `
    <p class="invisible">{{ action.error() }}</p>
    <test-frame>
      <table>
        <tr>
          <td>
            <p class="invisible">{{ action.error() }}</p>
          </td>
        </tr>
      </table>

      <p dialogAction class="invisible">{{ action.error() }}</p>
      <p class="invisible">{{ action.error() }}</p>
    </test-frame>
  `,
})
export class Host {
  declare action: HilosTrackedAction
}
