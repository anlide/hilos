<!-- HilosTermsPage — the tier-2 public /terms page (HIL-501): the text of the
Terms revision in force, read from the project's legal catalog, with the
project's own prose as an optional introduction (the default slot) above it.

Top to bottom: the heading, the reader's line, the introduction and the text.
Revision history has its own page. The reader's line says where the person stands - a guest
since when the revision is in force, a signed-in person which revision they
hold - and, while a decision is due, carries the deadline plate, "Accept the
new revision" and "What changed". Only the Terms are accepted here: the
documents are independent, and Privacy accepts its own revision. The line turns green on the server's answer,
never ahead of it.

Nothing here touches the browser when it renders: the store starts on mount,
so the prerendered file carries the heading, the introduction and the loading
line under it, and the text arrives with the subscription. Bootstrap classes
only, no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  createHilosLegalTermsStore,
  describeHilosLegalTermsReader,
  formatHilosLegalDate,
  hilosLegalReconsentPerson,
  LEGAL_RECONSENT_COPY,
  LEGAL_TERMS_COPY,
  sessionAccountStanding,
  type HilosLegalContext,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import HilosStaticPage from '../HilosStaticPage.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosLegalChanges from '../legal/HilosLegalChanges.vue'
import HilosLegalDocumentReader from '../legal/HilosLegalDocumentReader.vue'
import HilosLegalRevisionText from '../legal/HilosLegalRevisionText.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{
  /** The connection, scopes and action lifecycle the page reads and accepts through. */
  context: HilosLegalContext
  /** Optional navigation across sibling public pages. */
  navigation?: HilosStaticPageNavigation
}>()

const copy = LEGAL_TERMS_COPY
const reconsentCopy = LEGAL_RECONSENT_COPY
const store = createHilosLegalTermsStore(props.context)
const terms = useSignal(store.terms)
const agreement = useSignal(store.agreement)
const accepting = useSignal(store.accepting)
const refusal = useSignal(store.refusal)
const person = useSignal(hilosLegalReconsentPerson)
const standing = useSignal(sessionAccountStanding(props.context.scopes))
/** Whether the comparison of the held revision with the one in force is open. */
const comparing = ref(false)

const line = computed(() =>
  describeHilosLegalTermsReader(terms.value, agreement.value, {
    person: person.value,
    frozen: standing.value?.frozen === true,
    now: Date.now(),
  }),
)
const changes = computed(() =>
  comparing.value && line.value.canCompare
    ? (terms.value?.changes ?? null)
    : null,
)
const changesTitle = computed(() =>
  changes.value === null
    ? ''
    : copy.changesTitle
        .replace('{from}', publishedOf(changes.value.fromRevisionId))
        .replace('{to}', publishedOf(changes.value.toRevisionId)),
)
// A comparison that has nothing left to show - accepted, or the revision moved - is closed, not parked.
watch(changes, (value) => {
  if (value === null) comparing.value = false
})

let stop: (() => void) | null = null
onMounted(() => {
  stop = store.start()
})
onUnmounted(() => {
  stop?.()
})

/**
 * The publication day of a revision the page lists, or its id when it lists none.
 *
 * @param revisionId The revision.
 */
function publishedOf(revisionId: string): string {
  const revision = terms.value?.revisions.find(
    (item) => item.revisionId === revisionId,
  )

  return revision ? formatHilosLegalDate(revision.publishedOn) : revisionId
}

async function onAccept(): Promise<void> {
  if (await store.accept()) {
    comparing.value = false
  }
}
</script>

<template>
  <HilosStaticPage
    title="Terms"
    icon="bi-file-earmark-text"
    subtitle="The terms of using this project: the revision in force and every one before it."
    :navigation="props.navigation"
  >
    <div
      v-if="line.state !== 'unpublished'"
      class="mb-4"
      data-id="terms-reader"
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
      v-if="terms === undefined"
      class="text-body-secondary"
      data-id="terms-loading"
    >
      {{ copy.loading }}
    </p>
    <p
      v-else-if="terms === null"
      class="text-body-secondary"
      data-id="terms-none"
    >
      {{ copy.nonePublished }}
    </p>
    <div v-else data-id="terms-text">
      <HilosLegalRevisionText :clauses="terms.clauses" />
    </div>

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
      <div v-if="changes" data-id="terms-changes-modal">
        <HilosLegalChanges
          :changes="changes.changes"
          :from-label="publishedOf(changes.fromRevisionId)"
          :to-label="publishedOf(changes.toRevisionId)"
        />
        <div v-if="line.canAccept" class="mt-3">
          <HilosFormError
            :message="refusal"
            data-id="terms-changes-refusal"
            announce
          />
        </div>
      </div>
      <template #actions="{ requestClose }"
        ><button
          type="button"
          class="btn btn-secondary"
          data-id="terms-changes-close"
          :disabled="accepting"
          @click="requestClose"
        >
          {{ reconsentCopy.close }}</button
        ><LoadingButton
          v-if="line.canAccept"
          class="btn-primary"
          data-id="terms-changes-accept"
          :loading="accepting"
          @click="onAccept"
        >
          {{ reconsentCopy.accept }}
        </LoadingButton></template
      >
    </HilosModal>
  </HilosStaticPage>
</template>
