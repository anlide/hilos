<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
  createHilosLegalAgreementsStore,
  describeHilosLegalAgreement,
  formatHilosLegalDate,
  hilosLegalDocumentLabel,
  HILOS_PAGE_ROUTES,
  HilosPages,
  type HilosLegalAgreement,
  type HilosLegalChange,
  type HilosLegalClause,
  type HilosLegalContext,
} from '@hilos/core'
import HilosLink from '../HilosLink.vue'
import HilosModal from '../HilosModal.vue'
import HilosPageHeading from '../HilosPageHeading.vue'
import HilosLegalChanges from '../legal/HilosLegalChanges.vue'
import HilosLegalRevisionText from '../legal/HilosLegalRevisionText.vue'
import { useSignal } from '../useSignal.js'

defineOptions({ name: 'HilosProfileAgreementsPage' })
const props = defineProps<{ context: HilosLegalContext }>()
const store = createHilosLegalAgreementsStore(props.context)
const state = useSignal(store.state)
const texts = useSignal(store.texts)
let stop: (() => void) | null = null
onMounted(() => {
  stop = store.start()
})
onUnmounted(() => stop?.())
const rows = computed(
  () =>
    state.value?.documents.map((agreement) => ({
      agreement,
      ...describeHilosLegalAgreement(agreement),
    })) ?? [],
)
const textDialog = ref<{ title: string; clauses: HilosLegalClause[] } | null>(
  null,
)
const changesDialog = ref<{
  title: string
  changes: HilosLegalChange[]
  from: string
  to: string
} | null>(null)

function openText(agreement: HilosLegalAgreement): void {
  const text = texts.value?.documents.find(
    (entry) => entry.document === agreement.document,
  )
  if (!text) return
  const held = agreement.held
  textDialog.value = {
    title: `${hilosLegalDocumentLabel(agreement.document)} · ${formatHilosLegalDate((held ?? agreement.current).publishedOn)}`,
    clauses:
      held && held.revisionId !== agreement.current.revisionId
        ? (text.held ?? text.current)
        : text.current,
  }
}
function openChanges(agreement: HilosLegalAgreement): void {
  const text = texts.value?.documents.find(
    (entry) => entry.document === agreement.document,
  )
  if (!text || !agreement.held) return
  changesDialog.value = {
    title: `${hilosLegalDocumentLabel(agreement.document)} — what changed`,
    changes: text.changes,
    from: formatHilosLegalDate(agreement.held.publishedOn),
    to: formatHilosLegalDate(agreement.current.publishedOn),
  }
}
</script>

<template>
  <div data-id="profile-agreements-view">
    <HilosPageHeading />
    <template v-if="state && texts">
      <p class="small text-body-secondary">
        These are the revisions you accepted. The current text may have changed
        since then.
      </p>
      <p v-if="rows.length === 0" class="text-body-secondary">
        This project publishes no legal documents
      </p>
      <div
        v-for="row in rows"
        :key="row.agreement.document"
        class="py-3 border-bottom"
        data-id="legal-agreement-row"
        :data-document="row.agreement.document"
      >
        <div class="d-flex align-items-center gap-3">
          <i
            class="bi fs-5 text-body-secondary"
            :class="
              row.agreement.document === 'terms'
                ? 'bi-file-earmark-check'
                : 'bi-shield-check'
            "
            aria-hidden="true"
          ></i>
          <div class="flex-grow-1 text-break">
            <h2 class="h6 mb-1">
              {{ hilosLegalDocumentLabel(row.agreement.document) }}
            </h2>
            <div class="small" data-id="legal-agreement-state">
              {{ row.summary }}
            </div>
            <div class="small text-body-secondary">{{ row.standard }}</div>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-agreement-open"
            @click="openText(row.agreement)"
          >
            Open
          </button>
        </div>
        <div
          v-if="row.notice"
          class="alert small py-2 mt-3 mb-0"
          :class="
            row.agreement.standing === 'covered'
              ? 'alert-secondary'
              : 'alert-warning'
          "
          data-id="legal-agreement-notice"
        >
          {{ row.notice }}
          <button
            v-if="row.canCompare"
            type="button"
            class="btn btn-link btn-sm p-0 ms-1 align-baseline"
            data-id="legal-agreement-changes-open"
            @click="openChanges(row.agreement)"
          >
            What changed
          </button>
        </div>
      </div>
      <div class="d-flex align-items-center gap-3 py-3 border-bottom mt-3">
        <i
          class="bi bi-clock-history fs-5 text-body-secondary"
          aria-hidden="true"
        ></i>
        <div class="flex-grow-1"><h2 class="h6 mb-0">Revision history</h2></div>
        <HilosLink
          :to="HILOS_PAGE_ROUTES[HilosPages.PROFILE_AGREEMENTS_HISTORY]"
          class="btn btn-sm btn-outline-secondary"
          data-id="profile-agreements-history-open"
          >Open</HilosLink
        >
      </div>
    </template>
    <p v-else class="text-body-secondary">Loading agreements…</p>
    <HilosModal
      :model-value="textDialog !== null"
      :title="textDialog?.title ?? ''"
      initial-focus="dialog"
      size="wide"
      @update:model-value="
        (open) => {
          if (!open) textDialog = null
        }
      "
    >
      <div v-if="textDialog" data-id="legal-revision-text-modal">
        <HilosLegalRevisionText :clauses="textDialog.clauses" />
      </div>
      <template #actions="{ requestClose }"
        ><button
          type="button"
          class="btn btn-secondary"
          data-id="legal-text-close"
          @click="requestClose"
        >
          Close
        </button></template
      >
    </HilosModal>
    <HilosModal
      :model-value="changesDialog !== null"
      :title="changesDialog?.title ?? ''"
      initial-focus="dialog"
      size="wide"
      @update:model-value="
        (open) => {
          if (!open) changesDialog = null
        }
      "
    >
      <div v-if="changesDialog" data-id="legal-changes-modal">
        <HilosLegalChanges
          :changes="changesDialog.changes"
          :from-label="changesDialog.from"
          :to-label="changesDialog.to"
        />
      </div>
      <template #actions="{ requestClose }"
        ><button
          type="button"
          class="btn btn-secondary"
          data-id="legal-changes-close"
          @click="requestClose"
        >
          Close
        </button></template
      >
    </HilosModal>
  </div>
</template>
