<!-- The person card's account-merge section. It shows the progress the card
already holds: the status in words, the unfinished duties, the waits and the
server's refusal. The frame stays up for the life of the card, including while
no merge stands, and an empty progress is an empty voice — never a claim that
nothing is happening and never a claim of success. `successful` is not drawn:
the status line already says the state, and a false flag or an empty list is
not a second sentence. The status line and the refusal hold their room with an
invisible twin (styling-rules.md); the voice of each is a region that stands
before the words (accessibility.md). There is no control here to start, cancel,
choose a fate or prove anything. Bootstrap classes only. -->
<script setup lang="ts">
import { computed } from 'vue'

import {
  HILOS_USER_MERGE_COPY,
  type HilosUserMergeSection,
} from '@hilos/core/admin/users'

import HilosFormError from '../../HilosFormError.vue'

const props = defineProps<{
  /** The card's merge section, or null while no merge stands. */
  section: HilosUserMergeSection | null
}>()

/**
 * Every known status sentence. Stacked with the visible line, the cell is as
 * tall as the tallest of them at this width and font. A height of our own is
 * not ours to write (styling-rules.md, "The room a live message takes").
 */
const statusRoom = [
  HILOS_USER_MERGE_COPY.preparing,
  HILOS_USER_MERGE_COPY.waiting,
  HILOS_USER_MERGE_COPY.releasing,
  HILOS_USER_MERGE_COPY.refused,
  HILOS_USER_MERGE_COPY.completed,
  HILOS_USER_MERGE_COPY.notStarted,
  HILOS_USER_MERGE_COPY.ended,
] as const

/** The status in words, or nothing while no merge stands. */
const statusText = computed(() => props.section?.status ?? '')

/** Duties that have not finished. An absent section lists none. */
const pendingParts = computed(() => props.section?.pendingParts ?? [])

/** Participants still awaited. An absent section lists none. */
const waits = computed(() => props.section?.waits ?? [])

/** The server's refusal, or null unless this merge is refused. */
const refusal = computed(() => props.section?.refusal ?? null)
</script>

<template>
  <section class="mb-4" data-id="hilos-user-merge">
    <h2 class="h5">{{ HILOS_USER_MERGE_COPY.title }}</h2>
    <div class="card">
      <div class="card-body">
        <div
          class="visually-hidden"
          role="status"
          aria-live="polite"
          data-id="hilos-user-merge-status-live"
        >
          {{ statusText }}
        </div>
        <!-- The visible line carries no role: the region above announces it. -->
        <div class="hilos-stack">
          <p
            v-for="line in statusRoom"
            :key="line"
            class="fw-medium mb-0 invisible"
            aria-hidden="true"
            inert
          >
            {{ line }}
          </p>
          <p class="fw-medium mb-0" data-id="hilos-user-merge-status">
            {{ statusText }}
          </p>
        </div>
        <template v-if="pendingParts.length > 0">
          <h3 class="h6">{{ HILOS_USER_MERGE_COPY.parts }}</h3>
          <ul class="list-unstyled">
            <li
              v-for="part in pendingParts"
              :key="part.key"
              data-id="hilos-user-merge-part"
            >
              {{ part.participant
              }}<template v-if="part.account !== null">
                · #{{ part.account }}</template
              >
            </li>
          </ul>
        </template>
        <template v-if="waits.length > 0">
          <h3 class="h6">{{ HILOS_USER_MERGE_COPY.waits }}</h3>
          <ul class="list-unstyled">
            <li
              v-for="wait in waits"
              :key="wait.key"
              data-id="hilos-user-merge-wait"
            >
              {{ wait.participant
              }}<template v-if="wait.account !== null">
                · #{{ wait.account }}</template
              >
            </li>
          </ul>
        </template>
        <!-- The slot is the assertive region. The row inside it carries no
             role, and its idle twin holds the room while there is no refusal. -->
        <HilosFormError
          :message="refusal"
          data-id="hilos-user-merge-refusal"
          :details-title="HILOS_USER_MERGE_COPY.refusalDetails"
          announce
        />
      </div>
    </div>
  </section>
</template>
