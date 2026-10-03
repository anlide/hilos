<script setup lang="ts">
import {
  createHilosLegalAcceptancesExport,
  createHilosLegalAcceptancesTable,
  hilosLegalAcceptancesExportFilterLine,
  hilosLegalAcceptancesExportStatus,
  hilosLegalAcceptancesExportStatusRoom,
  hilosLegalDocumentLabel,
  HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY as EXPORT_COPY,
  HILOS_STEP_UP_COPY,
  HilosPages,
  LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { computed, onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosFormError from '../../HilosFormError.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosLink from '../../HilosLink.vue'
import HilosModal from '../../HilosModal.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import HilosStepUpStep from '../../auth/HilosStepUpStep.vue'
import { useSignal } from '../../useSignal.js'
const props = defineProps<{ context: HilosLegalContext }>()
const table = createHilosLegalAcceptancesTable(props.context)
const exporter = createHilosLegalAcceptancesExport(
  props.context,
  table.controller,
)
const node = useSignal(exporter.state)
const busy = useSignal(exporter.busy)
const refusal = useSignal(exporter.refusal)
const stepRefusal = useSignal(exporter.stepUp.refusal)
const shown = useSignal(exporter.open)
const open = computed({
  get: () => shown.value,
  set: (value: boolean) => {
    if (!value) exporter.close()
  },
})
const status = computed(() => hilosLegalAcceptancesExportStatus(node.value))
const filterLine = computed(() =>
  node.value === null ? '' : hilosLegalAcceptancesExportFilterLine(node.value),
)
const statusRoom = hilosLegalAcceptancesExportStatusRoom()
onMounted(() => {
  table.start()
  exporter.start()
})
onUnmounted(() => {
  exporter.dispose()
  table.dispose()
})
</script>
<template>
  <HilosAdminPage :page="HilosPages.LEGAL_ACCEPTANCES">
    <div class="d-flex flex-column align-items-end mb-3">
      <div class="visually-hidden" role="status" aria-live="polite">
        {{ status }}
      </div>
      <div class="visually-hidden" role="alert" aria-live="assertive">
        {{ shown ? '' : refusal }}
      </div>
      <div class="d-flex flex-wrap justify-content-end gap-2">
        <a
          v-if="node?.state === 'ready'"
          class="btn btn-sm btn-primary"
          :href="LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH"
          download
          data-id="legal-acceptances-export-download"
          >{{ EXPORT_COPY.download }}</a
        >
        <LoadingButton
          class="btn-sm btn-outline-primary"
          :loading="busy"
          :disabled="node?.state === 'preparing'"
          data-id="legal-acceptances-export"
          @click="exporter.export()"
          ><i class="bi bi-download me-1" aria-hidden="true"></i
          >{{ EXPORT_COPY.button }}</LoadingButton
        >
      </div>
      <div class="hilos-stack w-100 text-end mt-2">
        <div class="invisible" aria-hidden="true" inert>
          <p class="small mb-0">{{ statusRoom }}</p>
          <p class="small text-body-secondary text-truncate mb-0">
            {{ EXPORT_COPY.all }}
          </p>
        </div>
        <div v-if="node !== null">
          <p
            class="small mb-0"
            :data-id="`legal-acceptances-export-${node.state}`"
          >
            {{ status }}
          </p>
          <p
            class="small text-body-secondary text-truncate mb-0"
            data-id="legal-acceptances-export-filter"
          >
            {{ filterLine }}
          </p>
        </div>
      </div>
      <div class="w-100">
        <HilosFormError
          :message="shown ? null : refusal"
          data-id="legal-acceptances-export-error"
        />
      </div>
    </div>
    <div data-id="legal-acceptances-table">
      <HilosViewportTable :controller="table.controller">
        <template #cell-name="{ row }"
          ><div data-id="legal-acceptance-row" :data-record="row.rowKey">
            <strong><HilosHideable :value="row.name" /></strong>
            <div class="small text-body-secondary">
              <HilosHideable v-slot="{ value: email }" :value="row.email">{{
                email ?? 'No verified email'
              }}</HilosHideable>
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
    <HilosModal
      v-model="open"
      :title="HILOS_STEP_UP_COPY.title"
      initial-focus="inner"
    >
      <div class="visually-hidden" role="alert" aria-live="assertive">
        {{ stepRefusal ?? refusal }}
      </div>
      <form
        id="hilos-legal-acceptances-export-proof"
        data-id="legal-acceptances-export-step-up"
        @submit.prevent="exporter.confirm()"
      >
        <HilosStepUpStep :controller="exporter.stepUp" />
        <HilosFormError
          :message="refusal"
          data-id="legal-acceptances-export-order-error"
        />
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-outline-secondary"
          @click="requestClose"
        >
          {{ HILOS_STEP_UP_COPY.cancel }}
        </button>
        <LoadingButton
          class="btn-primary"
          type="submit"
          form="hilos-legal-acceptances-export-proof"
          :loading="busy"
          data-id="legal-acceptances-export-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
      </template>
    </HilosModal>
  </HilosAdminPage>
</template>
