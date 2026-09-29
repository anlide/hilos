<!-- The profile root (HIL-1169): the person's own line, the Account rows, a row per catalog section with its live summary, and the danger zone. -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosProfileEmailChangeFlow,
  createHilosProfileRenameFlow,
  createHilosProfileRootStore,
  hilosChildLinks,
  HILOS_PROFILE_ROOT_COPY as COPY,
  hilosProfileSectionIcon,
  hilosProfileSectionId,
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted } from 'vue'
import HilosAvatar from '../HilosAvatar.vue'
import HilosLink from '../HilosLink.vue'
import HilosPageHeading from '../HilosPageHeading.vue'
import LoadingButton from '../LoadingButton.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'
import { useSignal } from '../useSignal.js'
import HilosAccountDeletion from './HilosAccountDeletion.vue'
import HilosProfileEmailChange from './HilosProfileEmailChange.vue'
import HilosProfileRename from './HilosProfileRename.vue'

const props = defineProps<{
  context: HilosProfilePageContext
  binding: HilosProfileBinding
}>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosProfilePage requires a provided Hilos router.')
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const sections = computed(() =>
  hilosChildLinks(
    identity.value?.children ?? [],
    route.value.params,
    router.resolvePath,
  ),
)
const root = createHilosProfileRootStore(props.context, props.binding)
const signedIn = useSignal(root.signedIn)
const summaries = useSignal(root.summaries)
const verifiedEmail = useSignal(root.verifiedEmail)
const name = useSignal(props.binding.name)
const rename =
  props.binding.rename === null
    ? null
    : createHilosProfileRenameFlow(
        props.context,
        props.binding.name,
        props.binding.rename,
      )
// Change waits while the window asks whether the confirmation is needed.
const renameOpening = useSignal(
  computedSignal(() => rename?.stepUp.busy.get() ?? false),
)
const email = createHilosProfileEmailChangeFlow(props.context)
const emailOpening = useSignal(email.busy)

onMounted(() => root.start())
onUnmounted(() => {
  root.dispose()
  rename?.dispose()
  email.dispose()
})
</script>

<template>
  <section v-if="signedIn" data-id="profile-view">
    <HilosPageHeading />
    <div
      v-if="name"
      class="d-flex align-items-center gap-3 mt-3"
      data-id="profile-identity"
    >
      <HilosAvatar :name="name" size="lg" />
      <div class="flex-grow-1 text-break">
        <div class="h5 mb-0" data-id="profile-identity-name">{{ name }}</div>
        <div
          v-if="verifiedEmail"
          class="small text-body-secondary"
          data-id="profile-identity-email"
        >
          {{ verifiedEmail }}
        </div>
      </div>
    </div>
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
    </div>
    <p v-else class="text-body-secondary" data-id="profile-loading">
      {{ COPY.loading }}
    </p>
    <section class="mt-4" aria-labelledby="profile-sections-heading">
      <h2
        id="profile-sections-heading"
        class="h6 text-uppercase text-body-secondary mb-2"
      >
        {{ COPY.sections }}
      </h2>
      <div
        v-for="section in sections"
        :key="section.page"
        class="d-flex align-items-center gap-3 py-3 border-bottom"
        data-id="profile-section"
      >
        <i
          class="bi fs-5 text-body-secondary"
          :class="hilosProfileSectionIcon(section.page)"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1 text-break">
          <div class="fw-semibold small">{{ section.label }}</div>
          <div
            class="small text-body-secondary"
            :data-id="`${hilosProfileSectionId(section.page)}-summary`"
          >
            {{ summaries[section.page] ?? '' }}
          </div>
        </div>
        <HilosLink
          :to="section.to"
          class="btn btn-sm btn-outline-secondary"
          :data-id="`${hilosProfileSectionId(section.page)}-open`"
          >{{ COPY.open }}</HilosLink
        >
      </div>
    </section>
    <HilosAccountDeletion :context="context" />
    <HilosProfileRename v-if="rename" :flow="rename" />
    <HilosProfileEmailChange :flow="email" />
  </section>
  <!-- Nobody signed in, or the session is not known yet: a placeholder, never
  page content. The shell's auth gate mounts the sign-in surface in place once
  the AUTHENTICATED guard answers 401. -->
  <p v-else class="text-body-secondary" data-id="profile-loading">
    {{ COPY.loading }}
  </p>
</template>
