<!-- The Hilos impersonation settings page (HilosPages.SECURITY_IMPERSONATION,
HIL-1170): a thin project binding of the framework
HilosSecurityImpersonationPage to this app's impersonation context. The table,
the row view-model, the switches and the scope's modal are the framework's; the
project supplies only the context and registers the table on its backend.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosSecurityImpersonationPage } from '@hilos/vue'

import { hilosImpersonationContext } from './hilosImpersonationContext'

defineOptions({ name: 'SecurityImpersonationPage' })
</script>

<template>
  <HilosSecurityImpersonationPage :context="hilosImpersonationContext" />
</template>
