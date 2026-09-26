<script setup lang="ts">
import { HilosPageHeading, HilosProfileSessions, useSignal } from '@hilos/vue'

import {
  profileSessionActions,
  profileSessions,
} from './profileSessionsPage.js'

defineOptions({ name: 'ProfileSessionsPage' })

const sessions = useSignal(profileSessions)
</script>

<template>
  <section>
    <HilosPageHeading data-id="profile-sessions-heading" />
    <HilosProfileSessions
      :sessions="sessions"
      :actions="profileSessionActions"
    />
  </section>
</template>
