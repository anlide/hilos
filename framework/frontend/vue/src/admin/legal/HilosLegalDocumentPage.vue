<script setup lang="ts">
import {
  createHilosLegalConsentPreview,
  createHilosLegalReconsentPreview,
  hilosLegalDocumentLabel,
  HilosPages,
  LEGAL_RECONSENT_COPY,
  type HilosLegalContext,
  type HilosLegalDocumentKey,
} from '@hilos/core/base'
import {
  createHilosLegalDocument,
  createHilosLegalSettingsTable,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  HilosLegalSettingKey,
} from '@hilos/core/admin/legal'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import HilosLegalDocumentPageShell from './HilosLegalDocumentPageShell.vue'
import HilosModal from '../../HilosModal.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosLegalConsent from '../../legal/HilosLegalConsent.vue'
import HilosLegalReconsent from '../../legal/HilosLegalReconsent.vue'
import HilosLink from '../../HilosLink.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const details = useSignal(createHilosLegalDocument(props.context))
const settings = createHilosLegalSettingsTable(
  props.context,
  HilosPages.LEGAL_DOCUMENT,
)
const settingsRows = useSignal(settings.controller.rows)
const lapsedLabel = computed(() =>
  hilosLegalLapsedLabel(
    settingsRows.value.find(
      ({ row }) => row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
    )?.row?.value,
  ),
)
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
// The same read-only screen as a holder of the previous revision sees today.
const reconsent = createHilosLegalReconsentPreview(props.context)
const reconsentOpen = useSignal(reconsent.opened)
const reconsentPreview = useSignal(reconsent.preview)
const reconsentLoading = useSignal(reconsent.loading)
const reconsentError = useSignal(reconsent.error)
const reconsentView = useSignal(reconsent.view)
function openReconsent(document: string): void {
  void reconsent.open(document)
}
onMounted(settings.start)
onUnmounted(() => {
  settings.dispose()
  settings.controller.dispose()
  preview.close()
  reconsent.close()
})
</script>

<template>
  <HilosLegalDocumentPageShell
    :context="context"
    :page="HilosPages.LEGAL_DOCUMENT"
  >
    <template v-if="details">
      <div
        class="alert alert-secondary small text-break"
        data-id="legal-document-sources"
      >
        Standard sets: <code>{{ details.standardSetCatalogClass }}</code
        >.
        <template v-if="details.projectCatalogClass">
          Project revisions and deviations:
          <code>{{ details.projectCatalogClass }}</code
          >.
        </template>
        These declarations are read-only; publishing a revision means deploying
        it.
      </div>
      <section class="mb-4">
        <h2 class="h5">Current revision</h2>
        <div class="row row-cols-1 row-cols-sm-3 g-3">
          <div class="col">
            <section class="border rounded-3 p-3 h-100">
              <h3 class="small text-body-secondary fw-normal">Covered</h3>
              <div class="fs-4 fw-semibold" data-id="legal-count-covered">
                {{ details.covered }}
              </div>
            </section>
          </div>
          <div class="col">
            <section class="border rounded-3 p-3 h-100">
              <h3 class="small text-body-secondary fw-normal">
                Waiting for acceptance
              </h3>
              <div class="fs-4 fw-semibold" data-id="legal-count-window">
                {{ details.window }}
              </div>
            </section>
          </div>
          <div class="col">
            <section
              class="border rounded-3 p-3 h-100"
              data-id="legal-document-lapsed"
            >
              <h3 class="small text-body-secondary fw-normal">
                {{ lapsedLabel }}
              </h3>
              <div class="fs-4 fw-semibold">
                <HilosLink
                  v-if="details.lapsed > 0"
                  :to="hilosLegalLapsedHref(details.document)"
                  data-id="legal-count-lapsed-link"
                >
                  <span data-id="legal-count-lapsed">{{ details.lapsed }}</span>
                </HilosLink>
                <span v-else data-id="legal-count-lapsed">{{
                  details.lapsed
                }}</span>
              </div>
            </section>
          </div>
        </div>
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
        <button
          type="button"
          class="btn btn-outline-secondary ms-2"
          data-id="legal-preview-reconsent"
          @click="openReconsent(details.document)"
        >
          <i class="bi bi-arrow-repeat me-1" aria-hidden="true" />Re-consent
          screen
        </button>
      </section>
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
            {{ previewTerms.unpublishedMessage }}
          </p>
          <HilosLegalConsent
            v-else-if="previewTerms"
            v-model:accepted="accepted"
            v-model:reading="reading"
            :terms="previewTerms"
          />
          <p
            v-if="
              previewTerms?.form === 'line' && previewTerms.documents.length
            "
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
      <HilosModal
        :model-value="reconsentOpen"
        title="Re-consent screen"
        initial-focus="dialog"
        @update:model-value="
          (value) => {
            if (!value) reconsent.close()
          }
        "
      >
        <div data-id="legal-reconsent-preview">
          <HilosLegalReconsent
            variant="preview"
            :content="reconsentPreview"
            :view="reconsentView"
            :loading="reconsentLoading"
            :error="reconsentError"
            @retry="openReconsent(details.document)"
            @show="reconsent.show"
          />
        </div>
        <template #actions>
          <button
            type="button"
            class="btn btn-secondary"
            data-id="legal-reconsent-preview-close"
            @click="reconsent.close"
          >
            {{ LEGAL_RECONSENT_COPY.close }}
          </button>
        </template>
      </HilosModal>
    </template>
  </HilosLegalDocumentPageShell>
</template>
