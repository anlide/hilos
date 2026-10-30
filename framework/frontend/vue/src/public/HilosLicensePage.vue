<!-- The public License document: the build's project text stays literal. -->
<script setup lang="ts">
import type {
  HilosLicenseInventory,
  HilosStaticPageNavigation,
} from '@hilos/core/base'

import HilosStaticPage from '../HilosStaticPage.vue'

const props = defineProps<{
  /** The build-time snapshot, including the project's exact license text. */
  inventory: HilosLicenseInventory
  /** Optional navigation across sibling public pages. */
  navigation?: HilosStaticPageNavigation
}>()
</script>

<template>
  <HilosStaticPage
    title="License"
    icon="bi-journal-code"
    subtitle="The license of this project, and of everything it is built on."
    :navigation="props.navigation"
  >
    <pre
      class="small text-body mb-3 text-break hilos-pre-wrap"
      data-id="license-project-text"
      >{{ inventory.projectLicenseText }}</pre>
  </HilosStaticPage>
</template>
