<!-- The main page's skeleton (HIL-983): what the routed outlet draws while the
page waits for its first answer. It lays out on the grid Main.vue lays out on —
the "who is here" line, the event stream in the wide column, participants and
bots in the narrow one, the composer below — so nothing moves when the page
lands. Decorative from end to end: the outlet announces the wait once. The
composer holds the empty refusal's room and invisible copies of the paperclip
and of the button the session would show, so that row is already as wide as
the one that replaces it. -->
<script setup lang="ts">
import { computed } from 'vue'
import { HilosFormError, HilosSkeleton, useSignal } from '@hilos/vue/base'

import { currentUserId } from '../../bootstrap/session.js'

const selfId = useSignal(currentUserId)

// The live composer branches on whether the session names a user
// (Main.vue, `selfId.value !== null`): Send when it does, Sign in to send
// when it does not. The stub repeats those words so the row matches.
const isAuthenticated = computed(() => selfId.value !== null)
</script>

<template>
  <div class="d-flex flex-column h-100" aria-hidden="true">
    <p class="flex-shrink-0 placeholder-glow">
      <span class="placeholder col-3 rounded"></span>
    </p>

    <div class="row g-3 flex-grow-1 min-h-0">
      <div class="col-lg-8 d-flex flex-column min-h-0">
        <div class="card flex-grow-1 min-h-0 d-flex flex-column">
          <div class="card-header flex-shrink-0 placeholder-glow">
            <span class="placeholder col-3 rounded"></span>
          </div>
          <div class="card-body">
            <HilosSkeleton :lines="[5, 10, 7, 9, 4, 8]" />
          </div>
        </div>
      </div>

      <div class="col-lg-4 d-flex flex-column gap-3 min-h-0">
        <div v-for="card in 2" :key="card" class="card">
          <div class="card-header placeholder-glow">
            <span class="placeholder col-4 rounded"></span>
          </div>
          <div class="card-body">
            <HilosSkeleton :lines="[8, 6]" />
          </div>
        </div>
      </div>
    </div>

    <div class="mt-3 flex-shrink-0">
      <HilosFormError :message="null" data-id="main-skeleton-message-error" />
      <div
        class="d-flex gap-2 placeholder-glow"
        data-id="main-skeleton-composer"
      >
        <span
          class="btn btn-outline-secondary disabled placeholder flex-shrink-0"
        >
          <span class="invisible">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
          </span>
        </span>
        <span class="placeholder flex-grow-1 rounded"></span>
        <span class="btn btn-primary disabled placeholder flex-shrink-0">
          <span class="invisible">{{
            isAuthenticated ? 'Send' : 'Sign in to send'
          }}</span>
        </span>
      </div>
    </div>
  </div>
</template>
