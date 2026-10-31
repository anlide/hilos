import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosModerationKindPage } from '@hilos/angular/admin/moderation'

import { hilosModerationContext } from './hilosModerationContext.js'

@Component({
  selector: 'app-moderation-kind',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModerationKindPage],
  template: `<hilos-moderation-kind-page [context]="context" />`,
})
export class ModerationKind {
  protected readonly context = hilosModerationContext
}
