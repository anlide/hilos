<!-- Private view for one selected context and store lifetime. -->
<script setup lang="ts">
import {
  createHilosProfileHeadStore,
  createHilosProfilePhotoFlow,
  HILOS_PROFILE_PHOTO_COPY as PHOTO_COPY,
  HILOS_PROFILE_ROOT_COPY as COPY,
  type HilosProfileHeadStore,
  type HilosProfilePageContext,
} from '@hilos/core/base'
import { inject, onScopeDispose } from 'vue'
import HilosAvatar from '../HilosAvatar.vue'
import HilosPageHead from '../HilosPageHead.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'
import { useSignal } from '../useSignal.js'
import HilosProfilePhoto from './HilosProfilePhoto.vue'

const props = defineProps<{
  /** The profile page's connection, scopes and tracked actions. */
  context: HilosProfilePageContext
  /** A started store borrowed from the root; its caller owns its lifetime. */
  store?: HilosProfileHeadStore
}>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosProfileHead requires a provided Hilos router.')

const head = props.store ?? createHilosProfileHeadStore(props.context)
if (props.store === undefined) {
  head.start()
  onScopeDispose(() => head.dispose())
} else {
  head.claimNameBinding()
}
const identity = useSignal(head.identity)
const route = useSignal(router.currentRoute)
const name = useSignal(head.name)
const photo = useSignal(head.photo)
const photoEnabled = useSignal(head.photoEnabled)
const verifiedEmail = useSignal(head.verifiedEmail)
const passkeyOnly = useSignal(head.passkeyOnly)
const photoFlow = createHilosProfilePhotoFlow(props.context, head.photo)
onScopeDispose(() => photoFlow.dispose())
</script>

<template>
  <div data-id="profile-identity">
    <HilosPageHead
      :identity="identity"
      :page="route.page ?? ''"
      :params="route.params"
      :resolve-path="router.resolvePath"
      kind="entity"
      tabs-label="Profile pages"
      title-data-id="profile-identity-name"
    >
      <template #visual>
        <button
          v-if="photoEnabled"
          type="button"
          data-id="profile-photo-open"
          class="btn p-0 border-0 bg-transparent position-relative"
          :title="PHOTO_COPY.open"
          :aria-label="PHOTO_COPY.open"
          @click="photoFlow.open()"
        >
          <HilosAvatar :name="name" :photo="photo" size="lg" />
          <span
            class="position-absolute hilos-avatar-camera bg-body border rounded-circle d-inline-flex align-items-center justify-content-center text-body-emphasis"
            aria-hidden="true"
            ><i class="bi bi-camera"></i
          ></span>
        </button>
        <HilosAvatar v-else :name="name" :photo="photo" size="lg" />
      </template>
      <template #description>
        <p
          v-if="passkeyOnly"
          class="small text-warning-emphasis mb-0"
          data-id="profile-sign-in-passkey-only-line"
        >
          <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i
          >{{ COPY.passkeyOnly }}
        </p>
        <p
          v-else-if="verifiedEmail"
          class="small text-body-secondary mb-0"
          data-id="profile-identity-email"
        >
          {{ verifiedEmail }}
        </p>
      </template>
    </HilosPageHead>
    <HilosProfilePhoto v-if="photoEnabled" :flow="photoFlow" />
  </div>
</template>
