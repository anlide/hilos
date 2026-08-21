<!-- HilosMaintenance — the maintenance surface of the app shell. HilosLayout
renders it over the routed content for as long as the connection reports
protected mode, on every url, so a visitor who arrives mid-maintenance sees
planned work rather than a generic outage. The words come from the backend
registry and travel the wire (the Hilos i18n model); this component holds
layout only and falls back to PROTECTED_MODE_FALLBACK_COPY when the state is
known but no sentence arrived with it. It is a state, not a page: no links, no
retry button — the mode lifts on its own and the core reloads the document. The
one exception is the code field, shown only while the freeze says it accepts a
pass AND the shell hands over an administrative surface: that phase is the
verification window, and a verifier admitted by the code sees the whole product
rather than this screen, while a visitor on a public url is not invited to fill
in a key he was never given. The rule lives here, in the component that owns the
field, rather than in the shell. Submitting reconnects with the key on the socket
url (the core does that), because a client refused every outbound frame can only
ask to be let in on the 101. -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'
import {
  PROTECTED_MODE_FALLBACK_COPY,
  PROTECTED_MODE_PASS_COPY,
} from '@hilos/core'

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
    <form
      v-if="status.acceptsPass && adminSurface"
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
