// HilosLicensePage — the public /license page: the project's own license text.
// The build-time inventory remains a complete input so the project mount can
// pass the same snapshot to the sibling Dependencies page.
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import {
  type HilosLicenseInventory,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import { HilosStaticPage } from '../HilosStaticPage.js'

/** The public page that presents the project's own license text. */
@Component({
  selector: 'hilos-license-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStaticPage],
  template: `
    <hilos-static-page
      title="License"
      icon="bi-journal-code"
      subtitle="The license of this project, and of everything it is built on."
      [navigation]="navigation()"
    >
      <pre
        class="small text-body mb-3 text-break hilos-pre-wrap"
        data-id="license-project-text"
        >{{ inventory().projectLicenseText }}</pre>
    </hilos-static-page>
  `,
})
export class HilosLicensePage {
  /** The build-time snapshot of everything this build stands on. */
  readonly inventory = input.required<HilosLicenseInventory>()
  /** Optional navigation across sibling public pages. */
  readonly navigation = input<HilosStaticPageNavigation>()
}
