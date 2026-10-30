<!-- The public License owner's License mount over the build snapshot. -->
<script setup lang="ts">
import { HILOS_LICENSE_TABS, HilosPages } from '@hilos/core/base'
import { HilosLicensePage } from '@hilos/vue/base'

import { hilosLicenseInventory } from '../../generated/hilosLicenseInventory.js'
import { router } from '../../pages/routes.js'

defineOptions({ name: 'LicensePage' })
</script>

<template>
  <HilosLicensePage
    :inventory="hilosLicenseInventory"
    :navigation="{
      page: HilosPages.LICENSE,
      tabs: HILOS_LICENSE_TABS,
      resolvePath: router.path,
    }"
  />
</template>
