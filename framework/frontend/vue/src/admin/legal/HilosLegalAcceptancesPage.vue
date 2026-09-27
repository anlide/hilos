<script setup lang="ts">
import {
  createHilosLegalAcceptancesTable,
  hilosLegalDocumentLabel,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
const props = defineProps<{ context: HilosLegalContext }>()
const table = createHilosLegalAcceptancesTable(props.context)
onMounted(() => table.start())
onUnmounted(() => table.dispose())
</script>
<template>
  <HilosAdminPage :page="HilosPages.LEGAL_ACCEPTANCES">
    <div data-id="legal-acceptances-table">
      <HilosViewportTable :controller="table.controller">
        <template #cell-name="{ row }"
          ><div data-id="legal-acceptance-row" :data-record="row.rowKey">
            <strong>{{ row.name }}</strong>
            <div class="small text-body-secondary">
              {{ row.email ?? 'No verified email' }}
            </div>
          </div></template
        >
        <template #cell-document="{ row }">{{
          hilosLegalDocumentLabel(row.document)
        }}</template>
        <template #cell-revisionId="{ row }"
          >{{ row.revisionId }}
          <span v-if="row.declared === false" class="badge text-bg-warning"
            >Not in code</span
          ></template
        >
        <template #cell-actions="{ row }"
          ><HilosLink
            :to="
              resolveHilosPath(HilosPages.USER, { userId: String(row.userId) })
            "
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-acceptance-person"
            >Account</HilosLink
          ></template
        >
      </HilosViewportTable>
    </div>
    <p class="small text-body-secondary mt-3">
      Acceptance records cannot be edited or deleted here. A record names one
      person, one document, one exact revision and the time of acceptance.
      Refusal and silence create no acceptance record.
    </p>
  </HilosAdminPage>
</template>
