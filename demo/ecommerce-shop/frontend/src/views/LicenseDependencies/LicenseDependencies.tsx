// The public dependencies page is a thin SDK mount over this build's inventory.
import { HilosLicenseDependenciesPage } from '@hilos/react/base'
import { HILOS_LICENSE_TABS, HilosPages } from '@hilos/core/base'

import { hilosLicenseInventory } from '../../generated/hilosLicenseInventory.js'
import { router } from '../../pages/routes.js'

export default function LicenseDependencies() {
  return (
    <HilosLicenseDependenciesPage
      inventory={hilosLicenseInventory}
      navigation={{
        page: HilosPages.LICENSE_DEPENDENCIES,
        tabs: HILOS_LICENSE_TABS,
        resolvePath: router.path,
      }}
    />
  )
}
