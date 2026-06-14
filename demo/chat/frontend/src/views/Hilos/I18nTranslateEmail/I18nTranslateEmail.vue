<!-- The Translate email admin page (HilosPages.I18N_TRANSLATE_EMAIL). A thin binding of the page key to
the framework admin shell (HilosAdminPage), which resolves the label, lead,
breadcrumb, and sub-section children from the core admin tree. Bootstrap classes
only (styling-rules.md). -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core'
import { HilosAdminPage } from '@hilos/vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_TRANSLATE_EMAIL" />
</template>
