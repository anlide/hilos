<!-- The public Privacy page reads the policy and the reader's standing from one
page answer. The project supplies an optional introduction. The browser erase
stays available without a policy or a socket; its result belongs to the erase,
independently of legal refreshes and of the new guest session.
The browser values are erased synchronously before forgetting the in-memory
protected-mode pass and dispatching the server erase. Nothing starts on render. -->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import {
  BROWSER_ERASE_ACTION,
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
  createHilosLegalPrivacyStore,
  describeHilosLegalPrivacyReader,
  formatHilosLegalDate,
  hilosLegalReconsentPerson,
  LEGAL_PRIVACY_COPY,
  LEGAL_RECONSENT_COPY,
  sessionAccountStanding,
  subscribeSignal,
  type HilosLegalAgreement,
  type HilosLegalContext,
  type HilosLegalPrivacy,
  type HilosLegalPrivacyStore,
  type HilosAccountStanding,
  type HilosBrowserValue,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import HilosStaticPage from '../HilosStaticPage.vue'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosLegalChanges from '../legal/HilosLegalChanges.vue'
import HilosLegalDocumentReader from '../legal/HilosLegalDocumentReader.vue'
import HilosLegalRevisionText from '../legal/HilosLegalRevisionText.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'
import HilosPrivacyEraseModal from './HilosPrivacyEraseModal.vue'

const props = withDefaults(
  defineProps<{
    /** The connection, scopes and action lifecycle for public reading and browser erase. */
    context: HilosLegalContext
    /** The project's own declarations, swept beside the framework's. */
    values?: readonly HilosBrowserValue[]
    /** Optional navigation across sibling public pages. */
    navigation?: HilosStaticPageNavigation
  }>(),
  { values: () => [], navigation: undefined },
)

/** Everything this browser is declared to hold: the framework's list plus the project's. */
const registry = computed(() => [...HILOS_BROWSER_VALUES, ...props.values])

/** One line per registry entry, in declaration order, as the confirmation shows them. */
const labels = computed(() => registry.value.map((value) => value.label))

const erase = useTrackedAction()
const { busy, error } = erase

const confirming = ref(false)

/** What the erase did, once it has run; null while the block is still an offer. */
const swept = ref<string[] | null>(null)

const copy = LEGAL_PRIVACY_COPY
const reconsentCopy = LEGAL_RECONSENT_COPY
let store: HilosLegalPrivacyStore | null = null
const privacy = shallowRef<HilosLegalPrivacy | null | undefined>(undefined)
const agreement = shallowRef<HilosLegalAgreement | null | undefined>(undefined)
const accepting = ref(false)
const refusal = ref<string | null>(null)
const person = useSignal(hilosLegalReconsentPerson)
const standing = shallowRef<HilosAccountStanding | null>(null)
const comparing = ref(false)
const line = computed(() =>
  describeHilosLegalPrivacyReader(privacy.value, agreement.value, {
    person: person.value,
    frozen: standing.value?.frozen === true,
    now: Date.now(),
  }),
)
const changes = computed(() =>
  comparing.value && line.value.canCompare
    ? (privacy.value?.changes ?? null)
    : null,
)
const changesTitle = computed(() =>
  changes.value === null
    ? ''
    : copy.changesTitle
        .replace('{from}', publishedOf(changes.value.fromRevisionId))
        .replace('{to}', publishedOf(changes.value.toRevisionId)),
)
watch(changes, (value) => {
  if (value === null) comparing.value = false
})
let stop: (() => void) | null = null
onMounted(() => {
  stop = watch(
    () => props.context,
    (context, _, onCleanup) => {
      comparing.value = false
      const current = createHilosLegalPrivacyStore(context)
      store = current
      const off = current.start()
      const account = sessionAccountStanding(context.scopes)
      privacy.value = current.privacy.get()
      agreement.value = current.agreement.get()
      accepting.value = current.accepting.get()
      refusal.value = current.refusal.get()
      standing.value = account.get()
      const mirrors = [
        subscribeSignal(current.privacy, (value) => {
          privacy.value = value
        }),
        subscribeSignal(current.agreement, (value) => {
          agreement.value = value
        }),
        subscribeSignal(current.accepting, (value) => {
          accepting.value = value
        }),
        subscribeSignal(current.refusal, (value) => {
          refusal.value = value
        }),
        subscribeSignal(account, (value) => {
          standing.value = value
        }),
      ]
      onCleanup(() => {
        mirrors.forEach((unsubscribe) => unsubscribe())
        off()
        store = null
      })
    },
    { immediate: true },
  )
})
onUnmounted(() => stop?.())

/** The publication day of a revision, falling back to its id. */
function publishedOf(revisionId: string): string {
  const revision = privacy.value?.revisions.find(
    (item) => item.revisionId === revisionId,
  )
  return revision ? formatHilosLegalDate(revision.publishedOn) : revisionId
}
async function onAccept(): Promise<void> {
  if (line.value.canAccept && (await store?.accept())) comparing.value = false
}

async function onConfirm(): Promise<void> {
  if (busy.value) {
    return
  }

  // 1. The browser half, first and synchronous. It cannot fail: a storage that is
  // absent or refuses is reported as nothing swept rather than as an error.
  const erased = eraseBrowserValues(registry.value, {
    sessionCookieName: props.context.connection.sessionCookieName,
  })

  // 2. The pass the connection also holds IN MEMORY and re-presents on every
  // reconnect. Erasing only its mirror in storage would buy the admission back on
  // the socket that comes next.
  props.context.connection.forgetProtectedModePass()

  // 3. The server half. The confirmation stays open with its button loading until
  // this settles, and only then does the block become the outcome — either way,
  // success or refusal. Drawing the outcome before the reply would state that this
  // browser's session was ended while the question was still on the wire, and on a
  // timeout it would state it wrongly for thirty seconds.
  await erase.run(props.context.actions.dispatch(BROWSER_ERASE_ACTION, {}))
  confirming.value = false
  swept.value = erased
}
</script>

<template>
  <HilosStaticPage
    title="Privacy"
    icon="bi-shield-lock"
    subtitle="What this project keeps about you: the revision in force, every one before it, and wiping this browser."
    :navigation="props.navigation"
  >
    <div
      v-if="line.state !== 'unpublished'"
      class="mb-4"
      data-id="privacy-reader"
      :data-state="line.state"
    >
      <HilosLegalDocumentReader
        :line="line"
        :copy="copy"
        :accepting="accepting"
        :refusal="comparing ? null : refusal"
        @accept="onAccept"
        @compare="comparing = true"
      />
    </div>

    <slot />

    <p
      v-if="privacy === undefined"
      class="text-body-secondary"
      data-id="privacy-loading"
    >
      {{ copy.loading }}
    </p>
    <p
      v-else-if="privacy === null"
      class="text-body-secondary"
      data-id="privacy-none"
    >
      {{ copy.nonePublished }}
    </p>
    <div v-else data-id="privacy-text">
      <HilosLegalRevisionText :clauses="privacy.clauses" />
    </div>

    <section class="border rounded p-3 mt-4 mb-3">
      <h2 class="h6 text-uppercase text-body-secondary mb-2">
        Erase everything stored on this device
      </h2>

      <template v-if="swept === null">
        <p class="small text-body-secondary">
          Signs you out and clears every cookie and local value this site has
          kept — as if you had never opened it.
        </p>
        <!-- Never disabled, in any state, including with the socket down: a control
      that is off and cannot say why is the defect HIL-661 exists to fix, and the
      browser half runs offline just as well. -->
        <button
          type="button"
          class="btn btn-outline-danger btn-sm"
          data-id="privacy-erase"
          @click="confirming = true"
        >
          <i class="bi bi-eraser me-1" aria-hidden="true"></i>
          Erase and sign out
        </button>
      </template>

      <div role="status" aria-live="polite">
        <div
          v-if="swept !== null"
          data-id="privacy-erase-done"
          class="alert mb-0"
          :class="error === null ? 'alert-success' : 'alert-warning'"
        >
          <p class="small mb-2">
            {{ swept.length }} of the things this browser kept were erased.
          </p>
          <ul class="small mb-2 ps-3">
            <li v-for="label in swept" :key="label">{{ label }}</li>
          </ul>
          <p v-if="error === null" class="small mb-2">
            This browser's session was ended and a new one begun, so the sign-in
            cookie is not the one it arrived with. Other tabs come back on the
            new one, signed out.
          </p>
          <p v-else class="small mb-2" data-id="privacy-erase-partial">
            The values above are gone, but this browser's session could not be
            ended: {{ error }}
          </p>
          <p class="small mb-0">
            Nothing about your account changed. This is not account deletion.
          </p>
        </div>
      </div>
    </section>

    <HilosPrivacyEraseModal
      v-model="confirming"
      :labels="labels"
      :busy="busy"
      @confirm="onConfirm"
    />
    <HilosModal
      :model-value="changes !== null"
      :title="changesTitle"
      :busy="accepting"
      initial-focus="dialog"
      size="wide"
      @update:model-value="
        (open) => {
          if (!open) comparing = false
        }
      "
    >
      <div v-if="changes" data-id="privacy-changes-modal">
        <HilosLegalChanges
          :changes="changes.changes"
          :from-label="publishedOf(changes.fromRevisionId)"
          :to-label="publishedOf(changes.toRevisionId)"
        />
        <div v-if="line.canAccept" class="mt-3">
          <HilosFormError
            :message="refusal"
            data-id="privacy-changes-refusal"
            announce
          />
        </div>
      </div>
      <template #actions="{ requestClose }"
        ><button
          type="button"
          class="btn btn-secondary"
          data-id="privacy-changes-close"
          :disabled="accepting"
          @click="requestClose"
        >
          {{ reconsentCopy.close }}</button
        ><LoadingButton
          v-if="line.canAccept"
          class="btn-primary"
          data-id="privacy-changes-accept"
          :loading="accepting"
          @click="onAccept"
        >
          {{ reconsentCopy.accept }}
        </LoadingButton></template
      >
    </HilosModal>
  </HilosStaticPage>
</template>
