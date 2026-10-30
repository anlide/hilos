<!-- The profile root (HIL-1169): the person's own line, the Account rows (Name, Email, Time zone, Theme), the shared profile head and the danger zone. -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosProfileEmailChangeFlow,
  createHilosProfileRenameFlow,
  createHilosProfileRootStore,
  createHilosTimeZonePicker,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  hilosImpersonation,
  hilosSignedIn,
  HILOS_PROFILE_ROOT_COPY as COPY,
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core/base'
import { onMounted, onUnmounted, ref } from 'vue'
import HilosProfileHead from './HilosProfileHead.vue'
import LoadingButton from '../LoadingButton.vue'
import { useSignal } from '../useSignal.js'
import HilosAccountDeletion from './HilosAccountDeletion.vue'
import HilosProfileEmailChange from './HilosProfileEmailChange.vue'
import HilosProfileRename from './HilosProfileRename.vue'
import HilosProfileTheme from './HilosProfileTheme.vue'
import HilosProfileTimeZone from './HilosProfileTimeZone.vue'

const props = defineProps<{
  context: HilosProfilePageContext
  binding: HilosProfileBinding
}>()
const root = createHilosProfileRootStore(props.context, props.binding)
root.start()
const signedIn = useSignal(root.signedIn)
const verifiedEmail = useSignal(root.verifiedEmail)
const theme = useSignal(root.theme)
const timeZone = useSignal(root.timeZone)
const impersonation = useSignal(hilosImpersonation)
const themeOpen = ref(false)
const timeZoneOpen = ref(false)
const timeZonePicker = createHilosTimeZonePicker(props.context)
const name = useSignal(props.binding.name)
const rename =
  props.binding.rename === null
    ? null
    : createHilosProfileRenameFlow(
        props.context,
        props.binding.name,
        props.binding.rename,
        { remember: true },
      )
// Change waits while the window asks whether the confirmation is needed.
const renameOpening = useSignal(
  computedSignal(() => rename?.stepUp.busy.get() ?? false),
)
const email = createHilosProfileEmailChangeFlow(props.context)
const emailOpening = useSignal(email.busy)

onMounted(() => rename?.start())
onUnmounted(() => {
  rename?.dispose()
  root.dispose()
  email.dispose()
  timeZonePicker.dispose()
})

function openTimeZone(): void {
  if (hilosSignedIn.get() && hilosImpersonation.get() === null) {
    timeZoneOpen.value = true
  }
}
</script>

<template>
  <section v-if="signedIn" data-id="profile-view">
    <HilosProfileHead v-if="name" :context="context" :store="root.head" />
    <h2 class="h6 text-uppercase text-body-secondary mt-4 mb-2">
      {{ COPY.account }}
    </h2>
    <div v-if="name" data-id="profile-detail">
      <div class="d-flex align-items-center gap-3 py-3 border-bottom">
        <i class="bi bi-person fs-5 text-body-secondary" aria-hidden="true"></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">{{ COPY.name }}</div>
          <div class="small text-body-secondary" data-id="profile-name">
            {{ name }}
          </div>
        </div>
        <LoadingButton
          v-if="rename"
          class="btn-outline-secondary btn-sm"
          :loading="renameOpening"
          data-id="profile-edit"
          @click="rename.open()"
          >{{ COPY.change }}</LoadingButton
        >
      </div>
      <div
        v-if="verifiedEmail"
        class="d-flex align-items-center gap-3 py-3 border-bottom"
      >
        <i
          class="bi bi-envelope fs-5 text-body-secondary"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">{{ COPY.email }}</div>
          <div class="small text-body-secondary" data-id="profile-email">
            {{ COPY.verified.replace('{address}', verifiedEmail) }}
          </div>
        </div>
        <LoadingButton
          class="btn-outline-secondary btn-sm"
          :loading="emailOpening"
          data-id="profile-email-change"
          @click="email.open(verifiedEmail)"
          >{{ COPY.change }}</LoadingButton
        >
      </div>
      <div
        class="d-flex align-items-center gap-3 py-3 border-bottom"
        data-id="profile-time-zone-row"
      >
        <i class="bi bi-clock fs-5 text-body-secondary" aria-hidden="true"></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">{{ COPY.timeZone }}</div>
          <div class="small text-body-secondary" data-id="profile-time-zone">
            {{ timeZone }}
          </div>
        </div>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="impersonation !== null"
          :aria-describedby="
            impersonation ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined
          "
          data-id="profile-time-zone-change"
          @click="openTimeZone"
        >
          {{ COPY.change }}
        </button>
      </div>
      <div
        v-if="theme !== null"
        class="d-flex align-items-center gap-3 py-3 border-bottom"
      >
        <i
          class="bi bi-circle-half fs-5 text-body-secondary"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">{{ COPY.theme }}</div>
          <div class="small text-body-secondary" data-id="profile-theme">
            {{ theme }}
          </div>
        </div>
        <button
          type="button"
          class="btn btn-outline-secondary btn-sm"
          :disabled="impersonation !== null"
          :aria-describedby="
            impersonation ? HILOS_IMPERSONATION_STRIP_TEXT_ID : undefined
          "
          data-id="profile-theme-change"
          @click="themeOpen = true"
        >
          {{ COPY.change }}
        </button>
      </div>
    </div>
    <p v-else class="text-body-secondary" data-id="profile-loading">
      {{ COPY.loading }}
    </p>
    <HilosAccountDeletion :context="context" />
    <HilosProfileRename v-if="rename" :flow="rename" />
    <HilosProfileEmailChange :flow="email" />
    <HilosProfileTheme v-model="themeOpen" />
    <HilosProfileTimeZone v-model="timeZoneOpen" :picker="timeZonePicker" />
  </section>
  <!-- Nobody signed in, or the session is not known yet: a placeholder, never
  page content. The shell's auth gate mounts the sign-in surface in place once
  the AUTHENTICATED guard answers 401. -->
  <p v-else class="text-body-secondary" data-id="profile-loading">
    {{ COPY.loading }}
  </p>
</template>
