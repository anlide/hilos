import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosModerationPage } from '@hilos/angular/admin/moderation'

import { hilosModerationContext } from './hilosModerationContext.js'

@Component({
  selector: 'app-moderation',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModerationPage],
  template: `<hilos-moderation-page [context]="context" />`,
})
export class Moderation {
  protected readonly context = hilosModerationContext
}
