// The Hilos language locales (HilosPages.I18N_LANGUAGE_LOCALES): a thin project
// binding of the framework HilosI18nLanguageLocalesPage to this app's language
// context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nLanguageLocalesPage } from '@hilos/angular/admin/i18n'

import { hilosI18nLanguageContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework language locales page. */
@Component({
  selector: 'app-i18n-language-locales',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nLanguageLocalesPage],
  template: `<hilos-i18n-language-locales-page [context]="context" />`,
})
export class I18nLanguageLocales {
  protected readonly context = hilosI18nLanguageContext
}
