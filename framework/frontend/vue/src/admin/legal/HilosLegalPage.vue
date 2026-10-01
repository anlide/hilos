<script setup lang="ts">
import {
  createHilosLegalChecksTable,
  createHilosLegalDocumentsTable,
  createHilosLegalSettingsTable,
  describeHilosLegalCheck,
  hilosLegalDocumentLabel,
  hilosLegalLapsedHref,
  hilosLegalLapsedLabel,
  HilosLegalSettingKey,
  HilosPages,
  LEGAL_CATALOG_REFUSAL_SECTION,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const documents = createHilosLegalDocumentsTable(props.context)
const checks = createHilosLegalChecksTable(props.context)
const settings = createHilosLegalSettingsTable(props.context, HilosPages.LEGAL)
const refusal = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
)
const settingsRows = useSignal(settings.controller.rows)
const documentRows = useSignal(documents.controller.rows)
const lapsedLabel = computed(() =>
  hilosLegalLapsedLabel(
    settingsRows.value.find(
      ({ row }) => row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
    )?.row?.value,
  ),
)
onMounted(() => {
  documents.start()
  checks.start()
  settings.start()
})
onUnmounted(() => {
  documents.dispose()
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
      <HilosViewportTable :controller="documents.controller">
        <template #cell-rowKey="{ row }">
          <span
            data-id="legal-document-row"
            :data-document="row.rowKey"
            class="fw-semibold"
            >{{ hilosLegalDocumentLabel(row.rowKey) }}</span
          >
          <span v-if="!row.declared" class="badge text-bg-warning ms-2"
            >Not in code</span
          >
        </template>
        <template #cell-revision="{ row }">
          <template v-if="row.revision">
            <div>
              {{ row.revision.publishedOn }} · {{ row.revision.significance }}
            </div>
            <div class="small text-body-secondary">
              Effective from {{ row.revision.effectiveOn }}
            </div>
          </template>
          <span v-else class="text-body-secondary">Not in code</span>
        </template>
        <template #cell-covered="{ row }"
          ><span data-id="legal-count-covered">{{
            row.covered
          }}</span></template
        >
        <template #cell-window="{ row }"
          ><span data-id="legal-count-window">{{ row.window }}</span></template
        >
        <!-- The count of the people past the deadline opens them in the
        people list (HIL-945); nobody to open, nothing to follow. -->
        <template #cell-lapsed="{ row }"
          ><HilosLink
            v-if="row.lapsed > 0"
            :to="hilosLegalLapsedHref(row.rowKey)"
            data-id="legal-count-lapsed-link"
            :data-document="row.rowKey"
            ><span data-id="legal-count-lapsed">{{
              row.lapsed
            }}</span></HilosLink
          ><span v-else data-id="legal-count-lapsed">{{ row.lapsed }}</span>
          <span class="small text-body-secondary">{{
            lapsedLabel
          }}</span></template
        >
        <template #cell-actions="{ row }"
          ><HilosLink
            :to="
              resolveHilosPath(HilosPages.LEGAL_DOCUMENT, {
                documentKey: row.rowKey,
              })
            "
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-document-open"
            :data-document="row.rowKey"
            >Open</HilosLink
          ></template
        >
      </HilosViewportTable>
      <HilosViewportTable
        v-if="documentRows.length"
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
      <section class="mt-4 small text-body-secondary">
        <h2 class="h6">What this section does not do</h2>
        <p>
          There is no text editor, publish button, or acceptance-window setting.
          Those decisions are declared in the project's code.
        </p>
        <h2 class="h6">Why this is under Access &amp; identity</h2>
        <p class="mb-0">
          Agreement belongs to a person and an exact revision. Its deadline can
          affect that person's access.
        </p>
      </section>
    </template>
  </HilosAdminPage>
</template>
