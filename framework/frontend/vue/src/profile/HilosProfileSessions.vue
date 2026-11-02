<script setup lang="ts">
import {
  endableProfileSessionCount,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core/base'
import { computed, ref } from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import { useTrackedAction } from '../useTrackedAction.js'
import HilosSessionRow from './HilosSessionRow.vue'

const props = defineProps<{
  /** Durable sessions and their current live tabs. */
  sessions: readonly HilosProfileSession[]
  /** Tracked commands that end one session or every other session. */
  actions: HilosProfileSessionActions
}>()

const expanded = ref<ReadonlySet<number>>(new Set())
const endingSessionId = ref<number | null>(null)
const endOthersOpen = ref(false)
const endAction = useTrackedAction()
const endOthersAction = useTrackedAction()
const selectedSession = computed(() =>
  props.sessions.find((session) => session.id === endingSessionId.value),
)
const endError = computed(() =>
  selectedSession.value === undefined
    ? 'This session has already ended'
    : endAction.error.value,
)
const endBusy = computed(() => endAction.busy.value)
const endOthersBusy = computed(() => endOthersAction.busy.value)
const endableCount = computed(() => endableProfileSessionCount(props.sessions))
const endOthersError = computed(() =>
  endableCount.value === 0
    ? 'Your other sessions have already ended'
    : endOthersAction.error.value,
)

function toggleTabs(sessionId: number): void {
  const next = new Set(expanded.value)
  if (next.has(sessionId)) {
    next.delete(sessionId)
  } else {
    next.add(sessionId)
  }
  expanded.value = next
}

function openEnd(sessionId: number): void {
  endAction.clearError()
  endingSessionId.value = sessionId
}

async function confirmEnd(): Promise<void> {
  const session = selectedSession.value
  if (session === undefined) {
    return
  }
  if (await endAction.run(props.actions.endSession(session.id))) {
    endingSessionId.value = null
  }
}

function openEndOthers(): void {
  endOthersAction.clearError()
  endOthersOpen.value = true
}

async function confirmEndOthers(): Promise<void> {
  if (endableCount.value === 0) {
    return
  }
  if (await endOthersAction.run(props.actions.endOtherSessions())) {
    endOthersOpen.value = false
  }
}
</script>

<template>
  <section aria-label="Sessions" data-id="profile-sessions">
    <p class="small text-body-secondary">
      A session is a browser that signed in. Its open tabs are listed inside it.
      Ending a session takes effect at once.
    </p>

    <div v-if="sessions.length === 0" class="text-body-secondary">
      No sessions.
    </div>
    <div v-else>
      <HilosSessionRow
        v-for="session in sessions"
        :key="session.id"
        :session="session"
        :expanded="expanded.has(session.id)"
        id-prefix="profile-session"
        @toggle="toggleTabs(session.id)"
      >
        <template #badge>
          <span
            v-if="session.current"
            class="badge text-bg-success ms-1"
            data-id="profile-session-this"
            >this one</span
          >
          <span
            v-if="session.impersonated"
            class="badge bg-warning-subtle text-warning-emphasis ms-1"
            data-id="profile-session-impersonated"
            >an administrator is working as you</span
          >
        </template>
        <template #actions>
          <button
            v-if="!session.current && !session.impersonated"
            type="button"
            class="btn btn-outline-danger btn-sm"
            data-id="profile-session-revoke"
            @click="openEnd(session.id)"
          >
            Revoke
          </button>
        </template>
      </HilosSessionRow>
    </div>

    <button
      type="button"
      class="btn btn-outline-danger btn-sm mt-3"
      :disabled="endableCount === 0"
      data-id="profile-sessions-end-others"
      @click="openEndOthers"
    >
      Sign out everywhere else
    </button>

    <HilosModal
      :model-value="endingSessionId !== null"
      :title="`End session #${endingSessionId ?? ''}`"
      :busy="endBusy"
      initial-focus="dialog"
      @update:model-value="endingSessionId = $event ? endingSessionId : null"
    >
      <p>
        Tabs of this session lose the account at once: where an account is
        needed, the sign-in form takes the place of the content.
      </p>
      <p>Your other sessions are not touched.</p>
      <HilosFormError
        :message="endError"
        data-id="profile-session-end-error"
        announce
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="endBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="endBusy"
          :disabled="selectedSession === undefined"
          data-id="profile-session-end-confirm"
          @click="confirmEnd"
        >
          End
        </LoadingButton>
      </template>
    </HilosModal>

    <HilosModal
      :model-value="endOthersOpen"
      title="Sign out everywhere else"
      :busy="endOthersBusy"
      initial-focus="dialog"
      @update:model-value="endOthersOpen = $event"
    >
      <p>
        Other sessions that will end:
        <strong data-id="profile-sessions-end-others-count">{{
          endableCount
        }}</strong
        >.
      </p>
      <p>
        Their tabs lose the account at once: where an account is needed, the
        sign-in form takes the place of the content.
      </p>
      <p>This session stays signed in.</p>
      <HilosFormError
        :message="endOthersError"
        data-id="profile-sessions-end-others-error"
        announce
      />
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="endOthersBusy"
          @click="requestClose"
        >
          Cancel
        </button>
        <LoadingButton
          class="btn-danger"
          :loading="endOthersBusy"
          :disabled="endableCount === 0"
          data-id="profile-sessions-end-others-confirm"
          @click="confirmEndOthers"
        >
          Sign out
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
