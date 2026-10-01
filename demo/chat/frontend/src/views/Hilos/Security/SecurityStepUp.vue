<!-- The Hilos page of operations that ask for confirmation
(HilosPages.SECURITY_STEP_UP, HIL-1204), a child of two-factor: a thin project
binding of the framework HilosSecurityStepUpPage to this app's two-factor
context. The table, the row view-model and the switch are the framework's; the
project supplies only the context and registers the table on its backend.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosSecurityStepUpPage } from '@hilos/vue'

import { hilosTwoFactorContext } from './hilosTwoFactorContext'

defineOptions({ name: 'SecurityStepUpPage' })
</script>

<template>
  <HilosSecurityStepUpPage :context="hilosTwoFactorContext" />
</template>
