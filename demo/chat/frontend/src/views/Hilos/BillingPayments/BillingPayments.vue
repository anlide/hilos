<!-- The billing payments admin page (HilosPages.BILLING_PAYMENTS) at
/hilos/billing/{providerId}/payments. A thin binding of the page key to the
framework admin shell (HilosAdminPage); a leaf page, so the shell shows the stub
body until the payments list is implemented. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core'
import { HilosAdminPage } from '@hilos/vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.BILLING_PAYMENTS" />
</template>
