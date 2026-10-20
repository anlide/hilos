<!-- The public License page (HilosPages.LICENSE). A framework-declared static
page; this project supplies the content — the root LICENSE file and dependency
inventory through the build-time snapshot. See views/About/About.vue. -->
<script setup lang="ts">
import { HilosLicensePage } from '@hilos/vue/base'

import { hilosLicenseInventory } from '../../generated/hilosLicenseInventory.js'

defineOptions({ name: 'LicensePage' })
</script>

<template>
  <HilosLicensePage :inventory="hilosLicenseInventory" />
</template>
