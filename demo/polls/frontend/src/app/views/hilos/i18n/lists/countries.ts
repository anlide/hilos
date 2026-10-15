// The Hilos countries page (HilosPages.I18N_COUNTRIES): a thin project binding of
// the framework HilosI18nCountriesPage to this app's country context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nCountriesPage } from '@hilos/angular'

import { hilosI18nCountryContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework countries list. */
@Component({
  selector: 'app-i18n-countries',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nCountriesPage],
  template: `<hilos-i18n-countries-page [context]="context" />`,
})
export class I18nCountries {
  protected readonly context = hilosI18nCountryContext
}
