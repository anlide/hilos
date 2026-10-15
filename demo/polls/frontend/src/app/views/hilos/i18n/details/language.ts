// The Hilos language card (HilosPages.I18N_LANGUAGE): a thin project binding of
// the framework HilosI18nLanguagePage to this app's language context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nLanguagePage } from '@hilos/angular'

import { hilosI18nLanguageContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework language card. */
@Component({
  selector: 'app-i18n-language',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nLanguagePage],
  template: `<hilos-i18n-language-page [context]="context" />`,
})
export class I18nLanguage {
  protected readonly context = hilosI18nLanguageContext
}
