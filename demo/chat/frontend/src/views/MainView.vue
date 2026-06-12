<!-- The chat main page (PAGE_MAIN). Shows the session's current user, the live
event stream (messages plus registration/rename/lifecycle notices), the
participant roster, and the active bots — each fed by a main page list, so a new
message, registration, or presence flip appears without a refresh. Rendered by
HilosView when the navigator's route is the main page. -->
<script setup lang="ts">
import { useSignal } from '@hilos/vue'

import { currentUserName } from '../session'
import { mainParticipants, mainBots, mainEvents } from '../mainPage'

const selfName = useSignal(currentUserName)
const participants = useSignal(mainParticipants)
const bots = useSignal(mainBots)
const events = useSignal(mainEvents)
</script>

<template>
  <div class="container py-3">
    <p>Signed in as <span data-id="self-user">{{ selfName }}</span></p>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="events-header"
          >
            <strong>Event stream</strong>
            <span class="badge text-bg-secondary" data-id="events-count">{{
              events.length
            }}</span>
          </div>
          <div class="list-group list-group-flush">
            <div
              v-if="events.length === 0"
              class="list-group-item text-muted"
              data-id="events-empty"
            >
              No events yet
            </div>
            <div
              v-for="event in events"
              :key="event.key"
              class="list-group-item"
              data-id="event"
            >
              <div class="d-flex align-items-baseline gap-2">
                <span
                  v-if="event.authorName"
                  class="fw-semibold"
                  data-id="event-author"
                >
                  <span v-if="event.authorIsBot" class="me-1" aria-hidden="true"
                    >🤖</span
                  >{{ event.authorName }}
                </span>
                <span
                  v-if="event.description"
                  class="text-muted"
                  data-id="event-notice"
                  >{{ event.description }}</span
                >
              </div>
              <div v-if="event.text" class="mt-1" data-id="event-text">
                {{ event.text }}
              </div>
              <ul
                v-if="event.attachments.length > 0"
                class="list-inline mt-1 mb-0 small"
              >
                <li
                  v-for="attachment in event.attachments"
                  :key="attachment.key"
                  class="list-inline-item"
                  data-id="event-attachment"
                >
                  📎 {{ attachment.filename }}
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-3">
        <div class="card">
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
                  participant.presence === 'online'
                    ? 'bg-success'
                    : 'bg-secondary'
                "
                style="width: 10px; height: 10px"
              />
              <span data-id="participant-name">{{ participant.name }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <div
            class="card-header d-flex justify-content-between align-items-center"
            data-id="bots-header"
          >
            <strong>Bots</strong>
            <span class="badge text-bg-secondary" data-id="bots-count">{{
              bots.length
            }}</span>
          </div>
          <div class="list-group list-group-flush">
            <div
              v-if="bots.length === 0"
              class="list-group-item text-muted"
              data-id="bots-empty"
            >
              No bots yet
            </div>
            <div
              v-for="bot in bots"
              :key="bot.key"
              class="list-group-item"
              data-id="bot"
            >
              <div class="d-flex justify-content-between align-items-center gap-2">
                <span class="fw-semibold" data-id="bot-name">{{ bot.name }}</span>
                <span
                  v-if="bot.status"
                  class="badge text-bg-light"
                  data-id="bot-status"
                  >{{ bot.status }}</span
                >
              </div>
              <div v-if="bot.description" class="small text-muted">
                {{ bot.description }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
