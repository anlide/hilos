<!-- The chat main page (PAGE_MAIN). Shows the session's current user and the
live participant roster fed by the main page's `mainUsers` list — a new
registration appears here without a refresh. The event stream and the message
form land here next. Rendered by HilosView when the navigator's route is the
main page. -->
<script setup lang="ts">
import { useSignal } from '@hilos/vue'

import { currentUserName } from '../session'
import { mainParticipants } from '../mainPage'

const selfName = useSignal(currentUserName)
const participants = useSignal(mainParticipants)
</script>

<template>
  <div class="container py-3">
    <p>Signed in as <span data-id="self-user">{{ selfName }}</span></p>

    <div class="card" style="max-width: 22rem">
      <div
        class="card-header d-flex justify-content-between align-items-center"
        data-id="participants-header"
      >
        <strong>Participants</strong>
        <span class="badge text-bg-secondary" data-id="participants-count">{{
          participants.length
        }}</span>
      </div>
      <div class="list-group list-group-flush">
        <div
          v-if="participants.length === 0"
          class="list-group-item text-muted"
          data-id="participants-empty"
        >
          No participants yet
        </div>
        <div
          v-for="participant in participants"
          :key="participant.key"
          class="list-group-item d-flex align-items-center gap-2"
          data-id="participant"
        >
          <span
            class="rounded-circle flex-shrink-0"
            :class="
              participant.presence === 'online' ? 'bg-success' : 'bg-secondary'
            "
            style="width: 10px; height: 10px"
          />
          <span data-id="participant-name">{{ participant.name }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
