<!-- The Billing hub admin page (HilosPages.BILLING) at /hilos/billing. Like the
backend's one-file-per-page concrete classes, this view is a thin binding of the
page key to the framework admin shell (HilosAdminPage), which reads the page's
label, lead, and place in the tree from @hilos/core. A real billing section
graduates by replacing the shell's default body with its own content; until then
it shows the stub body. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core'
import { HilosAdminPage } from '@hilos/vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.BILLING" />
</template>
