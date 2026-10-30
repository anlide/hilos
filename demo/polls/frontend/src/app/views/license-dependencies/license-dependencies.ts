// The framework's public dependency inventory, mounted with this build's
// generated snapshot and the app's route resolver.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLicenseDependenciesPage } from '@hilos/angular/base'
import {
  HILOS_LICENSE_TABS,
  HilosPages,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import { hilosLicenseInventory } from '../../../generated/hilosLicenseInventory.js'
import { router } from '../../pages/routes.js'

@Component({
  selector: 'app-license-dependencies',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLicenseDependenciesPage],
  template: `<hilos-license-dependencies-page
    [inventory]="inventory"
    [navigation]="navigation"
  />`,
})
export class LicenseDependencies {
  /** The build-time snapshot this project's own build produced. */
  protected readonly inventory = hilosLicenseInventory
  /** Navigation shared by the public License pages. */
  protected readonly navigation: HilosStaticPageNavigation = {
    page: HilosPages.LICENSE_DEPENDENCIES,
    tabs: HILOS_LICENSE_TABS,
    resolvePath: (page, params) => router.path(page, params),
  }
}
