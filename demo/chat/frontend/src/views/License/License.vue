<!-- The public License page (HilosPages.LICENSE). A framework-declared static
page; this project supplies the content — the licence prose below, and the
build-time inventory the framework page draws under it. See views/About/About.vue. -->
<script setup lang="ts">
import { HilosLicensePage } from '@hilos/vue'

import { hilosLicenseInventory } from '../../generated/hilosLicenseInventory'

defineOptions({ name: 'LicensePage' })
</script>

<template>
  <HilosLicensePage :inventory="hilosLicenseInventory">
    <p>The Hilos framework is released under the MIT license.</p>
    <p>
      Permission is hereby granted, free of charge, to any person obtaining a
      copy of this software and associated documentation files, to deal in the
      software without restriction — including the rights to use, copy, modify,
      merge, publish, distribute, sublicense, and/or sell copies of it.
    </p>
    <p class="mb-0">
      The software is provided “as is”, without warranty of any kind.
    </p>
  </HilosLicensePage>
</template>
