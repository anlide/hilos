<!-- The public License owner's LicenseDependencies mount over the build snapshot. -->
<script setup lang="ts">
import { HILOS_LICENSE_TABS, HilosPages } from '@hilos/core/base'
import { HilosLicenseDependenciesPage } from '@hilos/vue/base'

import { hilosLicenseInventory } from '../../generated/hilosLicenseInventory.js'
import { router } from '../../pages/routes.js'

defineOptions({ name: 'LicenseDependenciesPage' })
</script>

<template>
  <HilosLicenseDependenciesPage
    :inventory="hilosLicenseInventory"
    :navigation="{
      page: HilosPages.LICENSE_DEPENDENCIES,
      tabs: HILOS_LICENSE_TABS,
      resolvePath: router.path,
    }"
  />
</template>
