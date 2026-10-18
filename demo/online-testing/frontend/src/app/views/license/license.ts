// The public License page (HilosPages.LICENSE). A framework-declared static
// page; this project supplies the content — the root LICENSE file and dependency
// inventory through the build-time snapshot. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosLicensePage } from '@hilos/angular'

import { hilosLicenseInventory } from '../../../generated/hilosLicenseInventory.js'

@Component({
  selector: 'app-license',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLicensePage],
  template: `<hilos-license-page [inventory]="inventory" />`,
})
export class License {
  /** The build-time snapshot this project's own build produced. */
  protected readonly inventory = hilosLicenseInventory
}
