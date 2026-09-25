<script setup lang="ts">
import { HilosProfileSessions, useSignal } from '@hilos/vue'

import {
  profileSessionActions,
  profileSessions,
} from './profileSessionsPage.js'

defineOptions({ name: 'ProfileSessionsPage' })

const sessions = useSignal(profileSessions)
</script>

<template>
  <section>
    <div class="d-flex flex-column gap-1 mb-4">
      <h1 class="h4 mb-0" data-id="profile-sessions-heading">Sessions</h1>
      <p class="mb-0 text-body-secondary">Where you are signed in.</p>
    </div>
    <HilosProfileSessions
      :sessions="sessions"
      :actions="profileSessionActions"
    />
  </section>
</template>
