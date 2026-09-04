// The Hilos settings admin page (HilosPages.SETTINGS). A framework-owned admin
// view: the framework owns the table, the row view-model, and the
// add / update / delete lifecycle (@hilos/angular HilosSettingsPage); this project
// binds only its scope stores and action lifecycle through hilosSettingsContext,
// and declares the catalog on its backend.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosSettingsPage } from '@hilos/angular'

import { hilosSettingsContext } from './hilosSettingsContext'

@Component({
  selector: 'app-settings',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosSettingsPage],
  template: `<hilos-settings-page [context]="context" />`,
})
export class Settings {
  protected readonly context = hilosSettingsContext
}
