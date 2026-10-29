<!-- Public revision history: core owns the window, labels and lazy reader. -->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import {
  createHilosLegalHistory,
  LEGAL_HISTORY_PUBLISHED_ON_FIELD,
  LEGAL_HISTORY_ORIGIN_FIELD,
  LEGAL_RECONSENT_COPY,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
  subscribeSignal,
  type HilosLegalRevisionDialog,
  type HilosLegalHistoryRow,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import HilosStaticPage from '../HilosStaticPage.vue'
import HilosViewportTable from '../HilosViewportTable.vue'
import HilosLegalRevisionText from '../legal/HilosLegalRevisionText.vue'

const props = defineProps<{
  context: HilosLegalContext
  document: HilosLegalDocumentKey
  navigation?: HilosStaticPageNavigation
}>()
const history = shallowRef(
  createHilosLegalHistory(props.context, props.document),
)
const dialog = shallowRef<HilosLegalRevisionDialog | null>(null)
watch(
  history,
  (current, _, onCleanup) => {
    dialog.value = current.reader.dialog.get()
    onCleanup(
      subscribeSignal(current.reader.dialog, (value) => {
        dialog.value = value
      }),
    )
  },
  { immediate: true },
)
const copy = computed(() => history.value.copy)
const title = ref('')
const terms = computed(() => props.document === 'terms')
let mounted = false
watch(
  () => [props.context, props.document] as const,
  () => {
    history.value.cleanup()
    history.value = createHilosLegalHistory(props.context, props.document)
    if (mounted) history.value.start()
  },
)
onMounted(() => {
  mounted = true
  history.value.start()
})
onUnmounted(() => history.value.cleanup())

function open(row: HilosLegalHistoryRow): void {
  title.value = row.revisionTitle
  void history.value.reader.open(row.revisionId)
}
</script>

<template>
  <HilosStaticPage
    :title="terms ? 'Terms' : 'Privacy'"
    :icon="terms ? 'bi-file-earmark-text' : 'bi-shield-lock'"
    :subtitle="
      terms
        ? 'The terms of using this project: the revision in force and every one before it.'
        : 'What this project keeps about you: the revision in force, every one before it, and wiping this browser.'
    "
    :navigation="navigation"
  >
    <div data-id="public-legal-history" :data-document="document">
      <HilosViewportTable :controller="history.controller">
        <template #[`cell-${LEGAL_HISTORY_PUBLISHED_ON_FIELD}`]="{ row }">
          <span class="fw-semibold">{{ row.publishedLabel }}</span>
          <span
            v-if="row.inForce"
            class="badge text-bg-primary ms-1"
            data-id="public-legal-history-current"
            :data-revision="row.revisionId"
            >{{ copy.inForce }}</span
          >
          <span
            v-if="row.accepted"
            class="badge text-bg-success ms-1"
            data-id="public-legal-history-accepted"
            :data-revision="row.revisionId"
            >{{ copy.youAccepted }}</span
          >
        </template>
        <template #[`cell-${LEGAL_HISTORY_ORIGIN_FIELD}`]="{ row }">
          <span class="text-body-secondary">{{ row.changeLabel }}</span>
        </template>
        <template #cell-actions="{ row }">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="public-legal-history-open"
            data-hilos-focus-return="public-legal-history-open"
            :data-document="document"
            :data-revision="row.revisionId"
            @click="open(row)"
          >
            {{ copy.open }}
          </button>
        </template>
      </HilosViewportTable>
    </div>
    <HilosModal
      :model-value="dialog !== null"
      :title="title"
      initial-focus="dialog"
      size="wide"
      @update:model-value="
        (shown) => {
          if (!shown) history.reader.close()
        }
      "
    >
      <div
        v-if="dialog"
        data-id="public-legal-history-modal"
        :data-document="document"
        :data-revision="dialog.revisionId"
      >
        <div class="visually-hidden" role="status" aria-live="polite">
          {{ dialog.busy ? copy.revisionLoading : '' }}
        </div>
        <p
          class="small text-body-secondary"
          :class="{ invisible: !dialog.busy }"
          data-id="legal-dialog-loading"
          :aria-hidden="!dialog.busy"
        >
          {{ copy.revisionLoading }}
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
      </div>
      <template #actions="{ requestClose }"
        ><button
          type="button"
          class="btn btn-secondary"
          data-id="public-legal-history-close"
          @click="requestClose"
        >
          {{ LEGAL_RECONSENT_COPY.close }}
        </button></template
      >
    </HilosModal>
  </HilosStaticPage>
</template>
