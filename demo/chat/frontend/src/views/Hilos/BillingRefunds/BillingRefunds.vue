<!-- The billing refunds admin page (HilosPages.BILLING_REFUNDS) at
/hilos/billing/{providerId}/refunds. A thin binding of the page key to the
framework admin shell (HilosAdminPage); a leaf page, so the shell shows the stub
body until the refunds list is implemented. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core'
import { HilosAdminPage } from '@hilos/vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.BILLING_REFUNDS" />
</template>
