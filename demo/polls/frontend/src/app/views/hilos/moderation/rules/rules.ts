import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosModerationKindRulesPage } from '@hilos/angular/admin/moderation'

import { hilosModerationContext } from '../hilosModerationContext.js'

@Component({
  selector: 'app-moderation-kind-rules',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosModerationKindRulesPage],
  template: `<hilos-moderation-kind-rules-page [context]="context" />`,
})
export class ModerationKindRules {
  protected readonly context = hilosModerationContext
}
