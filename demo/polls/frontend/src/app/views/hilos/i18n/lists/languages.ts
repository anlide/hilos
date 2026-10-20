// The Hilos languages page (HilosPages.I18N_LANGUAGES): a thin project binding of
// the framework HilosI18nLanguagesPage to this app's language context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nLanguagesPage } from '@hilos/angular/admin/i18n'

import { hilosI18nLanguageContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework languages list. */
@Component({
  selector: 'app-i18n-languages',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nLanguagesPage],
  template: `<hilos-i18n-languages-page [context]="context" />`,
})
export class I18nLanguages {
  protected readonly context = hilosI18nLanguageContext
}
