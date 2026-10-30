<script setup lang="ts">
import {
  createHilosLegalChecksTable,
  createHilosLegalOverview,
  createHilosLegalSettingsTable,
  describeHilosLegalCheck,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  HilosLegalSettingKey,
  HILOS_LEGAL_VALUE_COPY,
  LEGAL_CATALOG_REFUSAL_SECTION,
} from '@hilos/core/admin/legal'
import { HilosPages, type HilosLegalContext } from '@hilos/core/base'
import { computed, onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import HilosTableEmptyState from '../../HilosTableEmptyState.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const overview = useSignal(createHilosLegalOverview(props.context))
const checks = createHilosLegalChecksTable(props.context)
const settings = createHilosLegalSettingsTable(props.context, HilosPages.LEGAL)
const refusal = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
)
const settingsRows = useSignal(settings.controller.rows)
const settingsBody = useSignal(settings.controller.frame.body)
const checksBody = useSignal(checks.controller.frame.body)
const consent = computed(
  () =>
    settingsRows.value.find(
      ({ row }) => row?.rowKey === HilosLegalSettingKey.consentForm,
    )?.row,
)
const policy = computed(
  () =>
    settingsRows.value.find(
      ({ row }) => row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
    )?.row,
)
const lapsedLabel = computed(() => hilosLegalLapsedLabel(policy.value?.value))
onMounted(() => {
  checks.start()
  settings.start()
})
onUnmounted(() => {
  checks.dispose()
  settings.dispose()
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.LEGAL">
    <div
      v-if="typeof refusal === 'string'"
      class="alert alert-danger text-break"
      role="alert"
      data-id="legal-catalog-refusal"
    >
      <h2 class="h6">The legal document catalog could not be loaded</h2>
      <p class="mb-0">{{ refusal }}</p>
    </div>
    <template v-else>
      <div class="alert alert-secondary small">
        Document texts live in code. Standard sets belong to Hilos; revisions
        and deviations belong to the project. Publishing a revision means
        deploying it. This section records what is declared and who accepted it.
      </div>
      <div
        v-if="overview"
        class="row row-cols-2 row-cols-lg-3 g-3 mb-3"
        data-id="legal-overview"
      >
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="legal-overview-documents"
          >
            <h2 class="small text-body-secondary fw-normal">Documents</h2>
            <div class="fs-4 fw-semibold" data-id="legal-document-count">
              {{ overview.documentCount }}
            </div>
            <div class="small text-body-secondary">
              {{
                overview.documents
                  .map(({ documentLabel }) => documentLabel)
                  .join(' · ')
              }}
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="legal-overview-window"
          >
            <h2 class="small text-body-secondary fw-normal">
              Waiting for acceptance
            </h2>
            <dl v-if="overview.documents.length" class="mb-0">
              <div
                v-for="document in overview.documents"
                :key="document.document"
                class="d-flex flex-wrap justify-content-between gap-2"
                :data-document="document.document"
              >
                <dt class="small fw-normal">{{ document.documentLabel }}</dt>
                <dd class="fw-semibold mb-0" data-id="legal-count-window">
                  {{ document.window }}
                </dd>
              </div>
            </dl>
            <div v-else class="fs-4 fw-semibold" data-id="legal-count-window">
              0
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="legal-overview-lapsed"
          >
            <h2 class="small text-body-secondary fw-normal">
              {{ lapsedLabel }}
            </h2>
            <dl v-if="overview.documents.length" class="mb-0">
              <div
                v-for="document in overview.documents"
                :key="document.document"
                class="d-flex flex-wrap justify-content-between gap-2"
                :data-document="document.document"
              >
                <dt class="small fw-normal">{{ document.documentLabel }}</dt>
                <dd class="fw-semibold mb-0">
                  <HilosLink
                    v-if="document.lapsed > 0"
                    :to="hilosLegalLapsedHref(document.document)"
                    data-id="legal-count-lapsed-link"
                    ><span data-id="legal-count-lapsed">{{
                      document.lapsed
                    }}</span></HilosLink
                  >
                  <span v-else data-id="legal-count-lapsed">{{
                    document.lapsed
                  }}</span>
                </dd>
              </div>
            </dl>
            <div v-else class="fs-4 fw-semibold" data-id="legal-count-lapsed">
              0
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="legal-overview-newer-sets"
          >
            <h2 class="small text-body-secondary fw-normal">
              New framework sets
            </h2>
            <div class="fs-4 fw-semibold" data-id="legal-newer-set-count">
              {{ overview.newerSetCount }}
            </div>
            <div class="small text-body-secondary">
              Documents with a newer framework set available.
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="legal-overview-consent"
          >
            <h2 class="small text-body-secondary fw-normal">Consent</h2>
            <div v-if="consent" class="fs-4 fw-semibold">
              <HilosHideable v-slot="{ value: shown }" :value="consent.value">{{
                HILOS_LEGAL_VALUE_COPY[shown] ?? shown
              }}</HilosHideable>
            </div>
            <HilosTableEmptyState
              v-else-if="settingsBody === 'unavailable'"
              :controller="settings.controller"
              kind="unavailable"
            />
            <HilosSkeleton v-else />
            <div class="small text-body-secondary">
              How people agree at registration.
            </div>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="legal-overview-refusal"
          >
            <h2 class="small text-body-secondary fw-normal">Refusal</h2>
            <div v-if="policy" class="fs-4 fw-semibold">
              <HilosHideable v-slot="{ value: shown }" :value="policy.value">{{
                HILOS_LEGAL_VALUE_COPY[shown] ?? shown
              }}</HilosHideable>
            </div>
            <HilosTableEmptyState
              v-else-if="settingsBody === 'unavailable'"
              :controller="settings.controller"
              kind="unavailable"
            />
            <HilosSkeleton v-else />
            <div class="small text-body-secondary">
              What happens after a deadline.
            </div>
          </section>
        </div>
      </div>
      <HilosSkeleton v-else />
      <HilosViewportTable
        v-if="checksBody !== 'empty'"
        :controller="checks.controller"
        class="mt-4"
      >
        <template #cell-rowKey="{ row }">
          <div data-id="legal-check-row" :data-check="row.rowKey">
            <span
              class="badge me-2"
              :class="row.ok ? 'text-bg-success' : 'text-bg-warning'"
              >{{ row.ok ? 'OK' : 'Review' }}</span
            >
            <strong>{{ describeHilosLegalCheck(row).title }}</strong>
            <ul v-if="row.items.length" class="small mt-2 mb-0">
              <li
                v-for="(line, index) in describeHilosLegalCheck(row).lines"
                :key="index"
              >
                {{ line }}
              </li>
            </ul>
          </div>
        </template>
      </HilosViewportTable>
    </template>
  </HilosAdminPage>
</template>
