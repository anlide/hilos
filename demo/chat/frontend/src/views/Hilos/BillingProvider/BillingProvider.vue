<!-- The billing provider admin page (HilosPages.BILLING_PROVIDER) at
/hilos/billing/{providerId}. A thin binding of the page key to the framework
admin shell (HilosAdminPage); the shell resolves its breadcrumb and the Payments
/ Refunds sub-section cards from the route's providerId. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core'
import { HilosAdminPage } from '@hilos/vue'
</script>

<template>
  <HilosAdminPage :page="HilosPages.BILLING_PROVIDER" />
</template>
