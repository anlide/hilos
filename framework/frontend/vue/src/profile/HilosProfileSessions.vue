<script setup lang="ts">
import {
  endableProfileSessionCount,
  formatProfileDateTime,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'
import { computed, ref } from 'vue'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import { useTrackedAction } from '../useTrackedAction.js'

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
const endLoading = computed(() => endAction.loading.value)
const endBusy = computed(() => endAction.busy.value)
const endOthersLoading = computed(() => endOthersAction.loading.value)
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
    <p>
      A session is a browser that signed in. Its open tabs are listed inside it.
      Ending a session takes effect at once.
    </p>
    <div class="alert alert-info small" role="note">
      The list of tabs is what is happening right now and can lag behind in a
      cluster. The list of sessions cannot: it comes from the database.
    </div>

    <div v-if="sessions.length === 0" class="text-body-secondary">
      No sessions.
    </div>
    <div v-else class="d-flex flex-column gap-3">
      <article
        v-for="session in sessions"
        :key="session.id"
        class="card"
        data-id="profile-session-row"
      >
        <div class="card-body">
          <div
            class="d-flex flex-wrap align-items-start justify-content-between gap-2"
          >
            <div>
              <h3 class="h6 mb-1">Session #{{ session.id }}</h3>
              <div data-id="profile-session-device">
                {{ session.deviceName ?? 'Unknown device' }}
              </div>
              <span
                v-if="session.current"
                class="badge text-bg-primary me-1"
                data-id="profile-session-this"
                >this one</span
              >
              <span
                v-if="session.impersonated"
                class="badge text-bg-warning"
                data-id="profile-session-impersonated"
                >an administrator is working as you</span
              >
            </div>
            <button
              v-if="!session.current && !session.impersonated"
              type="button"
              class="btn btn-outline-danger btn-sm"
              data-id="profile-session-revoke"
              @click="openEnd(session.id)"
            >
              Revoke
            </button>
          </div>
          <div class="small text-body-secondary mt-2">
            created {{ formatProfileDateTime(session.createdAt) }} · last seen
            {{
              session.tabs.length > 0
                ? 'now'
                : formatProfileDateTime(session.lastSeenAt)
            }}
            · expires {{ formatProfileDateTime(session.expiresAt) }}
          </div>
          <button
            type="button"
            class="btn btn-link btn-sm px-0 mt-2"
            :aria-expanded="expanded.has(session.id)"
            data-id="profile-session-tabs"
            @click="toggleTabs(session.id)"
          >
            tabs: {{ session.tabs.length }}
          </button>
          <div v-if="expanded.has(session.id)" class="mt-2">
            <p
              v-if="session.tabs.length === 0"
              class="text-body-secondary small mb-0"
            >
              No open tabs: the sign-in stays valid, but the browser is closed
              right now.
            </p>
            <ul v-else class="list-group list-group-flush">
              <li
                v-for="tab in session.tabs"
                :key="tab.acceptKey"
                class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2"
                data-id="profile-session-tab"
              >
                <code>{{ tab.acceptKey.slice(0, 8) }}</code>
                <span class="small text-body-secondary">
                  opened {{ formatProfileDateTime(tab.connectedAt * 1000) }}
                  <span
                    v-if="tab.current"
                    class="badge text-bg-primary ms-1"
                    data-id="profile-session-tab-this"
                    >this tab</span
                  >
                </span>
              </li>
            </ul>
          </div>
        </div>
      </article>
    </div>

    <button
      type="button"
      class="btn btn-outline-danger mt-3"
      :disabled="endableCount === 0"
      data-id="profile-sessions-end-others"
      @click="openEndOthers"
    >
      Sign out everywhere else
    </button>

    <HilosModal
      :model-value="endingSessionId !== null"
      :title="`End session #${endingSessionId ?? ''}`"
      initial-focus="dialog"
      @update:model-value="endingSessionId = $event ? endingSessionId : null"
    >
      <p>
        Tabs of this session lose the account at once: where an account is
        needed, the sign-in form takes the place of the content.
      </p>
      <p>Your other sessions are not touched.</p>
      <HilosFormError :message="endError" data-id="profile-session-end-error" />
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
          :loading="endLoading"
          :disabled="selectedSession === undefined || endBusy"
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
          :loading="endOthersLoading"
          :disabled="endableCount === 0 || endOthersBusy"
          data-id="profile-sessions-end-others-confirm"
          @click="confirmEndOthers"
        >
          Sign out
        </LoadingButton>
      </template>
    </HilosModal>
  </section>
</template>
