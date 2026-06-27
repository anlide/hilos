<!-- The Hilos user detail page (HilosPages.USER): a thin project binding of the
framework HilosUserPage to this app's context. The profile selector, the inline
rename, and the success/fail handling are the framework's; the project supplies
only the shared HilosUsersContext (its scopes, connection, and user collection).
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosUserPage } from '@hilos/vue'

import { hilosUsersContext } from './hilosUsersContext'

defineOptions({ name: 'HilosUserPage' })
</script>

<template>
  <HilosUserPage :context="hilosUsersContext" />
</template>
