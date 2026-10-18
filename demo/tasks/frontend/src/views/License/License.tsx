// The public License page (HilosPages.LICENSE). A framework-declared static
// page; this project supplies the content — the root LICENSE file and dependency
// inventory through the build-time snapshot. See views/About/About.tsx.
import { HilosLicensePage } from '@hilos/react'

import { hilosLicenseInventory } from '../../generated/hilosLicenseInventory.js'

export default function License() {
  return <HilosLicensePage inventory={hilosLicenseInventory} />
}
