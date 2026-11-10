<!-- The Sessions tab of a person's card (HilosPages.USER_SESSIONS): a thin project
binding of the framework HilosUserSessionsPage to the same context the card's
Overview mounts. The list, its live updates, and the two endings are the
framework's; the project supplies only where the data lives. -->
<script setup lang="ts">
import { HilosUserSessionsPage } from '@hilos/vue/admin/users'

import { hilosUsersContext } from './hilosUsersContext.js'

defineOptions({ name: 'UserSessionsPage' })
</script>

<template>
  <HilosUserSessionsPage :context="hilosUsersContext" />
</template>
