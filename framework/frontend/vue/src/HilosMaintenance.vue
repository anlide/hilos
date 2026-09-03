<!-- HilosMaintenance — the maintenance surface of the app shell. HilosLayout
renders it over the routed content for as long as the connection reports
protected mode, on every url, so a visitor who arrives mid-maintenance sees
planned work rather than a generic outage. The words come from the backend
registry and travel the wire (the Hilos i18n model); this component holds
layout only and falls back to PROTECTED_MODE_FALLBACK_COPY when the state is
known but no sentence arrived with it. It is a state, not a page: no links, no
retry button — the mode lifts on its own and the core reloads the document. The
one exception is the code field, shown only while the freeze says it accepts a
pass AND the shell hands over an administrative surface AND at least one code has
been minted: that phase is the verification window, and a verifier admitted by the
code sees the whole product rather than this screen, while a visitor on a public
url is not invited to fill in a key he was never given. The window opens before
any code exists, and until one does the same spot carries a sentence saying so —
the field would otherwise be a box that can take nothing. The rule lives here, in
the component that owns the field, rather than in the shell. Submitting
reconnects with the key on the socket url (the core does that), because a client
refused every outbound frame can only ask to be let in on the 101.

The second exception is the restore panel, and it appears for one visitor only:
the admin whose own restore is what shuttered the node. Its frames are addressed
to that browser's session (HIL-655), so every other tab receives none and keeps
this screen exactly as it was. What it says is the phase, a bar with the share of
the work behind it and an estimate of what is left, and finally the outcome — not
the backup list, since under the freeze there is nobody left to serve a list. The
bar is here because this screen is now the operator's only view of their own
restore: the freeze holds every one of their tabs, the backups page included. -->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'
import {
  backupProgressPercent,
  createBackupProgressClock,
  createHilosRestoreProgress,
  formatBackupProgressLabel,
  formatRestoreOutcomeLine,
  formatRestorePhaseLine,
  PROTECTED_MODE_FALLBACK_COPY,
  PROTECTED_MODE_PASS_COPY,
} from '@hilos/core'

import { useSignal } from './useSignal.js'

const props = defineProps<{
  status: ProtectedModeStatus
  connection: HilosConnection
  /**
   * Whether the url under the freeze names an administrative surface, as the
   * shell reads it off the current route. Required, so a shell cannot forget to
   * answer and silently hide the field from the verifier who needs it.
   */
  adminSurface: boolean
}>()

const title = computed(
  () => props.status.title ?? PROTECTED_MODE_FALLBACK_COPY.title,
)
const message = computed(
  () => props.status.message ?? PROTECTED_MODE_FALLBACK_COPY.message,
)

const restoreProgress = createHilosRestoreProgress(props.connection)
const restoreStatus = useSignal(restoreProgress.status)
const restorePhaseLine = computed(() =>
  restoreStatus.value === null
    ? ''
    : formatRestorePhaseLine(restoreStatus.value),
)
const restoreOutcomeLine = computed(() =>
  restoreStatus.value === null
    ? ''
    : formatRestoreOutcomeLine(restoreStatus.value),
)

// A percentage moves with wall time while the socket only speaks on a change of
// phase, so the bar redraws from this ticker rather than from the frames.
const progressClock = createBackupProgressClock()
const progressNow = useSignal(progressClock.now)
const restorePercent = computed(() =>
  restoreStatus.value === null
    ? null
    : backupProgressPercent(restoreStatus.value, progressNow.value),
)
const restoreProgressLabel = computed(() =>
  restoreStatus.value === null
    ? ''
    : formatBackupProgressLabel(restoreStatus.value, progressNow.value),
)
// While the restore is still running there is no outcome sentence to read, and this
// is the screen the operator was moved to: the bar is what tells them it is moving.
const restoreRunning = computed(
  () => restoreStatus.value !== null && restoreOutcomeLine.value === '',
)

onMounted(() => {
  restoreProgress.start()
})
onUnmounted(() => {
  restoreProgress.dispose()
  progressClock.dispose()
})

const code = ref('')
const submittable = computed(() => code.value.trim() !== '')

// The typed code is deliberately kept after a submit: a rejection is most often a
// typo, and clearing the field would make the visitor retype the whole key.
function present(): void {
  props.connection.presentProtectedModePass(code.value)
}
</script>

<template>
  <div
    class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center"
    data-id="maintenance"
    :data-operation="status.operation"
    role="status"
    aria-live="polite"
  >
    <i
      class="bi bi-tools display-4 text-body-secondary mb-3"
      aria-hidden="true"
    ></i>
    <h1 class="h3 mb-2" data-id="maintenance-title">{{ title }}</h1>
    <p class="text-body-secondary mb-0" data-id="maintenance-message">
      {{ message }}
    </p>
    <div
      v-if="restoreStatus"
      class="alert mt-4 mb-0"
      :class="
        restoreStatus.outcome === 'error'
          ? 'alert-danger'
          : restoreStatus.outcome === 'success'
            ? 'alert-success'
            : 'alert-info'
      "
      data-id="maintenance-restore"
    >
      <div class="fw-semibold" data-id="maintenance-restore-phase">
        {{ restorePhaseLine }}
      </div>
      <div
        v-if="restoreOutcomeLine"
        class="small"
        data-id="maintenance-restore-outcome"
      >
        {{ restoreOutcomeLine }}
      </div>
      <template v-if="restoreRunning">
        <div
          class="progress mt-2"
          role="progressbar"
          aria-label="Restore progress"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="restorePercent ?? undefined"
          data-id="maintenance-restore-bar"
        >
          <div
            :class="
              restorePercent === null
                ? 'progress-bar progress-bar-striped progress-bar-animated'
                : 'progress-bar'
            "
            :style="{ width: `${restorePercent ?? 100}%` }"
          ></div>
        </div>
        <div class="small" data-id="maintenance-restore-progress">
          {{ restoreProgressLabel }}
        </div>
      </template>
    </div>
    <p
      v-if="status.acceptsPass && adminSurface && !status.passIssued"
      class="text-body-secondary small mt-4 mb-0"
      data-id="maintenance-pass-pending"
    >
      {{ PROTECTED_MODE_PASS_COPY.pending }}
    </p>
    <form
      v-if="status.acceptsPass && adminSurface && status.passIssued"
      class="row justify-content-center w-100 mt-4 px-3"
      data-id="maintenance-pass-form"
      @submit.prevent="present"
    >
      <div class="col-12 col-sm-8 col-md-5">
        <label
          class="form-label small text-body-secondary"
          for="maintenance-pass"
        >
          {{ PROTECTED_MODE_PASS_COPY.prompt }}
        </label>
        <div class="input-group">
          <input
            id="maintenance-pass"
            v-model="code"
            class="form-control"
            :class="{ 'is-invalid': status.passRejected }"
            data-id="maintenance-pass"
            type="text"
            autocomplete="off"
            :aria-invalid="status.passRejected"
            :aria-describedby="
              status.passRejected ? 'maintenance-pass-error' : undefined
            "
          />
          <button
            class="btn btn-primary"
            data-id="maintenance-pass-submit"
            type="submit"
            :disabled="!submittable"
          >
            {{ PROTECTED_MODE_PASS_COPY.submit }}
          </button>
        </div>
        <p
          v-if="status.passRejected"
          id="maintenance-pass-error"
          class="text-danger small mt-2 mb-0"
          data-id="maintenance-pass-error"
        >
          {{ PROTECTED_MODE_PASS_COPY.rejected }}
        </p>
      </div>
    </form>
  </div>
</template>
