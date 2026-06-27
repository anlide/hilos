// HilosI18nTranslateActionErrorPage — the I18n Translate Action Error admin page (HilosPages.I18N_TRANSLATE_ACTION_ERROR). A framework default: a
// thin binding of the page key to the shared admin shell HilosAdminPage, which
// resolves the heading, lead, breadcrumb, and any sub-section cards from the
// @hilos/core admin tree. Implement the page by projecting content into the
// shell's slot to replace its default body. Bootstrap classes only
// (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosPages } from '@hilos/core'

import { HilosAdminPage } from '../../../HilosAdminPage.js'

/** The I18n Translate Action Error admin page: the framework default shell for its key. */
@Component({
  selector: 'hilos-i18n-translate-action-error-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: `<hilos-admin-page [page]="page" />`,
})
export class HilosI18nTranslateActionErrorPage {
  /** The admin page key this default renders the shell for. */
  protected readonly page = HilosPages.I18N_TRANSLATE_ACTION_ERROR
}
