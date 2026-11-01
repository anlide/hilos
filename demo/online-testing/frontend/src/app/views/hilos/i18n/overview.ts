// The Hilos internationalization overview (HilosPages.I18N): a thin project binding
// of the framework HilosI18nPage to this app's overview context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nPage } from '@hilos/angular/admin/i18n'

import { hilosI18nOverviewContext } from './hilos-i18n-context.js'

/** Bind this project's context to the framework internationalization overview. */
@Component({
  selector: 'app-i18n-overview',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nPage],
  template: `<hilos-i18n-page [context]="context" />`,
})
export class I18nOverview {
  protected readonly context = hilosI18nOverviewContext
}
