<!-- The Hilos sign-in methods page (HilosPages.SECURITY_SIGN_IN_METHODS, HIL-427):
a thin project binding of the framework HilosSecuritySignInMethodsPage to this
app's context. The table, the row view-model and the switch are the framework's;
the project supplies only the context and declares its methods on its backend.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosSecuritySignInMethodsPage } from '@hilos/vue'

import { hilosSignInMethodsContext } from './hilosSignInMethodsContext'

defineOptions({ name: 'SecuritySignInMethodsPage' })
</script>

<template>
  <HilosSecuritySignInMethodsPage :context="hilosSignInMethodsContext" />
</template>
