<!-- The profile security page (HilosPages.PROFILE_SECURITY, /profile/security,
HIL-494): a thin project binding of the framework HilosProfileSecurityPage to
this app's connection, scopes and action lifecycle. The section, its modals and
its live copy are the framework's. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { type HilosSecondFactorContext } from '@hilos/core'
import { HilosProfileSecurityPage } from '@hilos/vue'

import { actions, connection } from '../../bootstrap/connection'
import { scopes } from '../../bootstrap/session'

defineOptions({ name: 'ProfileSecurityPage' })

const context: HilosSecondFactorContext = { connection, scopes, actions }
</script>

<template>
  <HilosProfileSecurityPage :context="context" />
</template>
