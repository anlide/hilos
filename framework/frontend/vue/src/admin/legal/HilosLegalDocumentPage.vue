<script setup lang="ts">
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  createHilosLegalConsentPreview,
  hilosLegalDocumentLabel,
  LEGAL_TERMS_UNPUBLISHED_MESSAGE,
  HilosPages,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_DOCUMENT_SECTION,
  legalAdminDocumentSchema,
  resolveHilosPath,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosModal from '../../HilosModal.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosLegalConsent from '../../legal/HilosLegalConsent.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosLegalDocumentPage requires a provided Hilos router.')
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const document = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_DOCUMENT_SECTION),
)
const refusal = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
)
const details = computed(() => {
  const parsed = legalAdminDocumentSchema.safeParse(document.value)
  return parsed.success ? parsed.data : null
})
const revisions = createHilosLegalRevisionsTable(props.context, documentKey)
const preview = createHilosLegalConsentPreview(props.context)
const previewOpen = useSignal(preview.opened)
const previewTerms = useSignal(preview.terms)
const previewLoading = useSignal(preview.loading)
const previewError = useSignal(preview.error)
const accepted = ref(false)
const reading = ref<HilosLegalDocumentKey | null>(null)
function openPreview(): void {
  accepted.value = false
  reading.value = null
  void preview.open()
}
onMounted(() => revisions.start())
onUnmounted(() => {
  revisions.dispose()
  preview.close()
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.LEGAL_DOCUMENT">
    <div
      v-if="typeof refusal === 'string'"
      class="alert alert-danger text-break"
      role="alert"
      data-id="legal-catalog-refusal"
    >
      <h2 class="h6">The legal document catalog could not be loaded</h2>
      <p class="mb-0">{{ refusal }}</p>
    </div>
    <template v-else-if="details">
      <div class="alert alert-secondary small">
        Document texts live in code. A new revision is published by a
        deployment.
      </div>
      <section v-if="details.set" data-id="legal-set" class="mb-4">
        <h2 class="h5">Hilos standard set {{ details.set.version }}</h2>
        <p class="small text-body-secondary">
          {{ details.set.publishedOn }} · {{ details.set.significance }} ·
          {{ details.set.clauses.length }} clauses
        </p>
        <ol class="list-group list-group-numbered">
          <li
            v-for="clause in details.set.clauses"
            :key="clause.clauseKey"
            class="list-group-item text-break"
          >
            <code class="small me-2">{{ clause.clauseKey }}</code
            >{{ clause.statement }}
          </li>
        </ol>
      </section>
      <section
        v-if="details.newerSet"
        class="alert alert-warning"
        data-id="legal-set-newer"
      >
        <h2 class="h6">
          Hilos has released set {{ details.newerSet.version }} for this
          document
        </h2>
        <p class="small">
          {{ details.newerSet.publishedOn }} ·
          {{ details.newerSet.significance }}. The project keeps its declared
          set until it publishes a new revision.
        </p>
        <ul class="mb-0 small">
          <li
            v-for="change in details.newerSet.changes"
            :key="change.clauseKey"
          >
            {{ change.kind }}: {{ change.title }}
            <code>{{ change.clauseKey }}</code>
          </li>
        </ul>
      </section>
      <section class="mb-4">
        <h2 class="h5">Different in this project</h2>
        <p v-if="!details.deviations.length" class="text-body-secondary">
          No project deviations are declared.
        </p>
        <div
          v-for="deviation in details.deviations"
          :key="deviation.clauseKey"
          class="border rounded p-3 mb-2 text-break"
          data-id="legal-deviation-row"
        >
          <h3 class="h6">
            {{ deviation.statement }}
            <span class="badge text-bg-secondary">{{
              deviation.direction
            }}</span>
          </h3>
          <p class="small text-body-secondary">
            Standard: {{ deviation.standardStatement }} ·
            <code>{{ deviation.clauseKey }}</code>
          </p>
          <p
            v-for="(paragraph, index) in deviation.text.split('\n\n')"
            :key="index"
            class="small mb-2"
          >
            {{ paragraph }}
          </p>
        </div>
      </section>
      <section class="mb-4">
        <h2 class="h5">How a person sees it</h2>
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="legal-preview-consent"
          @click="openPreview"
        >
          <i class="bi bi-eye me-1" aria-hidden="true" />Consent screen at
          registration
        </button>
      </section>
      <HilosViewportTable :controller="revisions.controller">
        <template #cell-rowKey="{ row }">
          <div data-id="legal-revision-row" :data-revision="row.rowKey">
            <strong>{{ row.rowKey }}</strong>
            <span v-if="row.current" class="badge text-bg-primary ms-2"
              >Current</span
            >
            <span v-if="!row.declared" class="badge text-bg-warning ms-2"
              >Not in code</span
            >
            <div v-if="row.revision" class="small text-body-secondary">
              {{ row.revision.publishedOn }} · {{ row.revision.significance }} ·
              Effective {{ row.revision.effectiveOn }} · Set
              {{ row.revision.setVersion }}
            </div>
          </div>
        </template>
        <template #cell-actions="{ row }"
          ><HilosLink
            :to="
              resolveHilosPath(HilosPages.LEGAL_REVISION, {
                documentKey: details.document,
                revisionId: row.rowKey,
              })
            "
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-revision-open"
            :data-revision="row.rowKey"
            >Open</HilosLink
          ></template
        >
      </HilosViewportTable>
    </template>
    <HilosModal
      :model-value="previewOpen"
      title="Consent screen at registration"
      initial-focus="dialog"
      @update:model-value="
        (value) => {
          if (!value) preview.close()
        }
      "
    >
      <div data-id="legal-consent-preview">
        <div class="visually-hidden" role="status" aria-live="polite">
          {{ previewError ?? (previewLoading ? 'Loading terms…' : '') }}
        </div>
        <div
          v-if="previewLoading"
          class="placeholder-glow"
          data-id="legal-consent-loading"
          aria-busy="true"
        >
          <span class="placeholder col-12" aria-hidden="true" />
          <span class="placeholder col-9" aria-hidden="true" />
        </div>
        <p
          v-if="previewTerms?.documents.length === 0"
          data-id="legal-consent-unpublished"
        >
          {{ LEGAL_TERMS_UNPUBLISHED_MESSAGE }}
        </p>
        <HilosLegalConsent
          v-else-if="previewTerms"
          v-model:accepted="accepted"
          v-model:reading="reading"
          :terms="previewTerms"
        />
        <p
          v-if="previewTerms?.form === 'line' && previewTerms.documents.length"
          class="small text-body-secondary mb-0"
          data-id="auth-consent-line"
        >
          By creating an account you accept the
          <template
            v-for="(item, index) in previewTerms.documents"
            :key="item.document"
          >
            <template v-if="index"> and the </template>
            <button
              type="button"
              class="btn btn-link btn-sm p-0"
              data-id="legal-consent-read"
              :data-document="item.document"
              @click="reading = item.document"
            >
              {{ hilosLegalDocumentLabel(item.document) }}
            </button> </template
          >.
        </p>
        <HilosFormError
          :message="previewError"
          data-id="legal-consent-preview-error"
        />
      </div>
      <template #actions>
        <button
          v-if="previewError"
          type="button"
          class="btn btn-primary"
          data-id="legal-consent-preview-retry"
          @click="openPreview"
        >
          Try again
        </button>
        <button
          type="button"
          class="btn btn-secondary"
          data-id="legal-consent-preview-close"
          @click="preview.close"
        >
          Close
        </button>
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
