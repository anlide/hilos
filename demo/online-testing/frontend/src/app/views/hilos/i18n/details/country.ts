// The Hilos country card (HilosPages.I18N_COUNTRY): a thin project binding of
// the framework HilosI18nCountryPage to this app's country context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nCountryPage } from '@hilos/angular'

import { hilosI18nCountryContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework country card. */
@Component({
  selector: 'app-i18n-country',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nCountryPage],
  template: `<hilos-i18n-country-page [context]="context" />`,
})
export class I18nCountry {
  protected readonly context = hilosI18nCountryContext
}
