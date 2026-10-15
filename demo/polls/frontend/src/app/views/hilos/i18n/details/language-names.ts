// The Hilos language names (HilosPages.I18N_LANGUAGE_NAMES): a thin project
// binding of the framework HilosI18nLanguageNamesPage to this app's language
// context.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosI18nLanguageNamesPage } from '@hilos/angular'

import { hilosI18nLanguageContext } from '../hilos-i18n-context.js'

/** Bind this project's context to the framework language names page. */
@Component({
  selector: 'app-i18n-language-names',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosI18nLanguageNamesPage],
  template: `<hilos-i18n-language-names-page [context]="context" />`,
})
export class I18nLanguageNames {
  protected readonly context = hilosI18nLanguageContext
}
