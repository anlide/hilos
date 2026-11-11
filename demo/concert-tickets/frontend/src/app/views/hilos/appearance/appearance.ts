// The Hilos Appearance admin page (HilosPages.APPEARANCE). A framework-owned
// admin view: the framework owns the table, both windows and the two writes
// (@hilos/angular HilosAppearancePage); this project binds only its scope
// stores and action lifecycle through hilosAppearanceContext.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosAppearancePage } from '@hilos/angular/admin/appearance'

import { hilosAppearanceContext } from './hilosAppearanceContext.js'

@Component({
  selector: 'app-appearance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAppearancePage],
  template: `<hilos-appearance-page [context]="context" />`,
})
export class Appearance {
  protected readonly context = hilosAppearanceContext
}
