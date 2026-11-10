// The Hilos country names (HilosPages.I18N_COUNTRY_NAMES): a thin project
// binding of the framework HilosI18nCountryNamesPage to this app's country
// context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nCountryNamesPage } from '@hilos/angular/admin/i18n'

import { hilosI18nCountryContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework country names page. */
@Component({
  selector: 'app-i18n-country-names',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nCountryNamesPage],
  template: `<hilos-i18n-country-names-page [context]="context" />`,
})
export class I18nCountryNames {
  protected readonly context = hilosI18nCountryContext
}
