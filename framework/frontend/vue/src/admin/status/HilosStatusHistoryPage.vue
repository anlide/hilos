<!-- Completed facts use the ordinary read-only viewport and shared Status head. -->
<script setup lang="ts">
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusHistoryState,
  createHilosStatusHistoryTable,
  hilosStatusHistoryDuration,
  HILOS_STATUS_COPY,
  HILOS_STATUS_HISTORY_COPY,
  STATUS_HISTORY_STARTED_AT_FIELD,
  STATUS_HISTORY_ENDED_AT_FIELD,
  STATUS_HISTORY_TITLE_FIELD,
  STATUS_HISTORY_OUTCOME_FIELD,
  type HilosStatusContext,
} from '@hilos/core/admin/status'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosStatusContext }>()
const state = createHilosStatusHistoryState(props.context)
const table = createHilosStatusHistoryTable(props.context)
const data = useSignal(state.data)
const timeZone = useSignal(hilosTimeZone)

function moment(at: number): string {
  void timeZone.value
  return (
    formatMoment(at, {
      format: {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
      },
    }) ?? '—'
  )
}

onMounted(() => {
  state.start()
  table.start()
})
onUnmounted(() => {
  table.dispose()
  state.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.STATUS_HISTORY"
    head-kind="section"
    tabs-label="Status pages"
  >
    <div class="hilos-stack mb-3">
      <p class="invisible mb-0" aria-hidden="true" inert>
        {{ HILOS_STATUS_HISTORY_COPY.unavailable }}
      </p>
      <p
        v-if="data === null"
        class="text-body-secondary mb-0"
        data-id="hilos-status-history-unavailable"
      >
        {{ HILOS_STATUS_HISTORY_COPY.unavailable }}
      </p>
    </div>
    <HilosViewportTable
      v-if="data !== null"
      :controller="table.controller"
      data-id="hilos-status-history-table"
    >
      <template #[`cell-${STATUS_HISTORY_STARTED_AT_FIELD}`]="{ row }">{{
        moment(row.startedAt)
      }}</template>
      <template #[`cell-${STATUS_HISTORY_ENDED_AT_FIELD}`]="{ row }">{{
        hilosStatusHistoryDuration(row)
      }}</template>
      <template #[`cell-${STATUS_HISTORY_TITLE_FIELD}`]="{ row }">
        <span
          class="text-break"
          :data-id="`hilos-status-history-title-${row.id}`"
        >
          <template v-if="row.kind === 'incident'">{{ row.title }}</template>
          <template v-else-if="!row.announcementKnown"
            >Maintenance: announcement unknown</template
          >
          <template v-else-if="row.workPublicId === null"
            >Unannounced maintenance</template
          >
          <template v-else>Maintenance «{{ row.title }}»</template>
        </span>
        <p
          v-if="row.kind === 'incident'"
          class="small text-body-secondary mb-0"
        >
          {{ HILOS_STATUS_COPY[row.severity] }}
        </p>
      </template>
      <template #[`cell-${STATUS_HISTORY_OUTCOME_FIELD}`]="{ row }">
        <template v-if="row.outcome === 'resolved'">
          <span v-if="row.publishedAt !== null"
            >Published at {{ moment(row.publishedAt) }}; resolved
            automatically</span
          >
          <span v-else>Resolved automatically</span>
        </template>
        <template v-else-if="row.outcome === 'recovered_before_publication'"
          >Not published: recovered before publication</template
        >
        <template v-else-if="row.outcome === 'canceled_publication'"
          >Not published: publication canceled manually</template
        >
        <template v-else-if="row.endedAt !== null">Completed</template>
        <template v-else>
          Completion observed at {{ moment(row.completionObservedAt!) }}
          <p class="small text-body-secondary mb-0">
            {{ HILOS_STATUS_HISTORY_COPY.unknownEnd }}
          </p>
        </template>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
