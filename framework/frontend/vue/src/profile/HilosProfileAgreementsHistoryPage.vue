<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import {
  createHilosLegalAgreementsStore,
  createHilosLegalRevisionReader,
  describeHilosLegalRevision,
  formatHilosLegalAcceptanceDate,
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  hilosLegalRevisionHistory,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from '@hilos/core'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import HilosPageHeading from '../HilosPageHeading.vue'
import HilosLegalChanges from '../legal/HilosLegalChanges.vue'
import HilosLegalRevisionText from '../legal/HilosLegalRevisionText.vue'
import { useSignal } from '../useSignal.js'

defineOptions({ name: 'HilosProfileAgreementsHistoryPage' })
const props = defineProps<{ context: HilosLegalContext }>()
const store = createHilosLegalAgreementsStore(props.context)
const state = useSignal(store.state)
const history = useSignal(hilosLegalRevisionHistory(props.context))
const reader = createHilosLegalRevisionReader(props.context)
const dialog = useSignal(reader.dialog)
const title = computed(() =>
  dialog.value
    ? `${hilosLegalDocumentLabel(dialog.value.document)} — ${dialog.value.kind === 'text' ? 'revision text' : 'what changed'}`
    : '',
)
let stop: (() => void) | null = null
onMounted(() => {
  stop = store.start()
})
onUnmounted(() => {
  stop?.()
  reader.close()
})
function acceptedAt(
  document: HilosLegalDocumentKey,
  revisionId: string,
): number | null {
  return (
    state.value?.documents
      .find((item) => item.document === document)
      ?.accepted.find((item) => item.revisionId === revisionId)?.acceptedAt ??
    null
  )
}
function current(document: HilosLegalDocumentKey): string | undefined {
  return state.value?.documents.find((item) => item.document === document)
    ?.current.revisionId
}
</script>

<template>
  <div data-id="profile-agreements-history-view">
    <HilosPageHeading />
    <template v-if="state">
      <p v-if="history.length === 0" class="text-body-secondary">
        This project publishes no legal documents
      </p>
      <section
        v-for="document in history"
        :key="document.document"
        class="mb-4"
        data-id="legal-history-document"
        :data-document="document.document"
      >
        <h2 class="h6 text-uppercase text-body-secondary mb-2">
          {{ hilosLegalDocumentLabel(document.document) }}
        </h2>
        <div
          v-for="revision in document.revisions.slice().reverse()"
          :key="revision.revisionId"
          class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
          data-id="legal-history-revision"
          :data-revision="revision.revisionId"
        >
          <i
            class="bi bi-file-earmark-text fs-5 text-body-secondary"
            aria-hidden="true"
          ></i>
          <div class="flex-grow-1 text-break">
            <div class="fw-semibold small">
              {{ formatHilosLegalDate(revision.publishedOn) }}
              <span
                v-if="current(document.document) === revision.revisionId"
                class="badge text-bg-primary ms-1"
                data-id="legal-history-current"
                >current</span
              >
              <span
                v-if="
                  acceptedAt(document.document, revision.revisionId) !== null
                "
                class="badge text-bg-success ms-1"
                data-id="legal-history-accepted"
                >you accepted ·
                {{
                  formatHilosLegalAcceptanceDate(
                    acceptedAt(document.document, revision.revisionId)!,
                  )
                }}</span
              >
            </div>
            <div class="small text-body-secondary">
              {{ describeHilosLegalRevision(revision) }}
            </div>
          </div>
          <div class="d-flex gap-2">
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="legal-history-open"
              @click="reader.open(document.document, revision.revisionId)"
            >
              Open
            </button>
            <button
              v-if="revision.origin !== 'first'"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="legal-history-compare"
              @click="reader.compare(document.document, revision.revisionId)"
            >
              Compare
            </button>
          </div>
        </div>
      </section>
    </template>
    <p v-else class="text-body-secondary">Loading revision history…</p>
    <HilosModal
      :model-value="dialog !== null"
      :title="title"
      initial-focus="dialog"
      size="wide"
      @update:model-value="
        (open) => {
          if (!open) reader.close()
        }
      "
    >
      <div
        v-if="dialog"
        :data-id="
          dialog.kind === 'text'
            ? 'legal-revision-text-modal'
            : 'legal-changes-modal'
        "
      >
        <div class="visually-hidden" role="status" aria-live="polite">
          {{ dialog.busy ? 'Loading revision…' : '' }}
        </div>
        <p
          class="small text-body-secondary"
          :class="{ invisible: !dialog.busy }"
          data-id="legal-dialog-loading"
          :aria-hidden="!dialog.busy"
        >
          Loading revision…
        </p>
        <HilosFormError
          :message="dialog.refusal"
          data-id="legal-dialog-refusal"
          announce
        />
        <HilosLegalRevisionText
          v-if="dialog.text"
          :clauses="dialog.text.clauses"
        />
        <HilosLegalChanges
          v-if="dialog.changes"
          :changes="dialog.changes.changes"
          :from-label="formatHilosLegalDate(dialog.changes.fromRevisionId)"
          :to-label="formatHilosLegalDate(dialog.changes.toRevisionId)"
        />
      </div>
      <template #actions="{ requestClose }"
        ><button
          type="button"
          class="btn btn-secondary"
          data-id="legal-history-close"
          @click="requestClose"
        >
          Close
        </button></template
      >
    </HilosModal>
  </div>
</template>
