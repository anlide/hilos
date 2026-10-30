<script setup lang="ts">
import {
  createHilosLegalDocumentsTable,
  createHilosLegalSettingsTable,
  hilosLegalLapsedHref,
  HilosLegalSettingKey,
  LEGAL_CATALOG_REFUSAL_SECTION,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import { onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const settings = createHilosLegalSettingsTable(
  props.context,
  HilosPages.LEGAL_DOCUMENTS,
)
const policy = computedSignal(
  () =>
    settings.controller.rows
      .get()
      .find(
        ({ row }) => row?.rowKey === HilosLegalSettingKey.refusalAfterDeadline,
      )?.row?.value,
)
const documents = createHilosLegalDocumentsTable(
  props.context,
  HilosPages.LEGAL_DOCUMENTS,
  policy,
)
const refusal = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
)
onMounted(() => {
  settings.start()
  documents.start()
})
onUnmounted(() => {
  documents.dispose()
  settings.dispose()
  documents.controller.dispose()
  settings.controller.dispose()
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.LEGAL_DOCUMENTS">
    <div
      v-if="typeof refusal === 'string'"
      class="alert alert-danger text-break"
      role="alert"
      data-id="legal-catalog-refusal"
    >
      <h2 class="h6">The legal document catalog could not be loaded</h2>
      <p class="mb-0">{{ refusal }}</p>
    </div>
    <HilosViewportTable v-else :controller="documents.controller">
      <template #cell-documentLabel="{ row }">
        <div
          class="fw-semibold"
          data-id="legal-document-row"
          :data-document="row.rowKey"
        >
          {{ row.documentLabel }}
        </div>
        <div class="small text-body-secondary">{{ row.rowKey }}</div>
        <span v-if="!row.declared" class="badge text-bg-warning"
          >Not in code</span
        >
      </template>
      <template #cell-publishedOn="{ row }">
        <template v-if="row.revision">
          <div>{{ row.publishedOn }} · {{ row.revision.significance }}</div>
          <div class="small text-body-secondary">
            Effective from {{ row.revision.effectiveOn }}
          </div>
        </template>
        <span v-else class="text-body-secondary">Not in code</span>
      </template>
      <template #cell-covered="{ row }">
        <span data-id="legal-count-covered">{{ row.covered }}</span>
      </template>
      <template #cell-window="{ row }">
        <span data-id="legal-count-window">{{ row.window }}</span>
      </template>
      <template #cell-lapsed="{ row }">
        <HilosLink
          v-if="row.lapsed > 0"
          :to="hilosLegalLapsedHref(row.rowKey)"
          data-id="legal-count-lapsed-link"
          :data-document="row.rowKey"
          ><span data-id="legal-count-lapsed">{{ row.lapsed }}</span></HilosLink
        >
        <span v-else data-id="legal-count-lapsed">{{ row.lapsed }}</span>
      </template>
      <template #cell-actions="{ row }">
        <HilosLink
          :to="
            resolveHilosPath(HilosPages.LEGAL_DOCUMENT, {
              documentKey: row.rowKey,
            })
          "
          class="btn btn-sm btn-outline-secondary"
          data-id="legal-document-open"
          :data-document="row.rowKey"
          >Open</HilosLink
        >
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
