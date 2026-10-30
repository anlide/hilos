// The public License page (HilosPages.LICENSE). A framework-declared static
// page; this project supplies the content — the root LICENSE file and dependency
// inventory through the build-time snapshot. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLicensePage } from '@hilos/angular/base'
import {
  HILOS_LICENSE_TABS,
  HilosPages,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import { hilosLicenseInventory } from '../../../generated/hilosLicenseInventory.js'
import { router } from '../../pages/routes.js'

@Component({
  selector: 'app-license',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLicensePage],
  template: `<hilos-license-page
    [inventory]="inventory"
    [navigation]="navigation"
  />`,
})
export class License {
  /** The build-time snapshot this project's own build produced. */
  protected readonly inventory = hilosLicenseInventory
  /** Navigation shared by the public License pages. */
  protected readonly navigation: HilosStaticPageNavigation = {
    page: HilosPages.LICENSE,
    tabs: HILOS_LICENSE_TABS,
    resolvePath: (page, params) => router.path(page, params),
  }
}
