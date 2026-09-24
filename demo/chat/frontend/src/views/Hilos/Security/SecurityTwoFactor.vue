<!-- The Hilos two-step verification page (HilosPages.SECURITY_2FA, HIL-494): a
thin project binding of the framework HilosSecurity2faPage to this app's context.
The table, the row view-model and the edit modal are the framework's; the project
supplies only the context and registers the table on its backend.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosSecurity2faPage } from '@hilos/vue'

import { hilosTwoFactorContext } from './hilosTwoFactorContext'

defineOptions({ name: 'SecurityTwoFactorPage' })
</script>

<template>
  <HilosSecurity2faPage :context="hilosTwoFactorContext" />
</template>
