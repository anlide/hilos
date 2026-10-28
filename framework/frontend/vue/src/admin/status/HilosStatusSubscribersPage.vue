<!-- The subscriber list and its aggregates share the complete page answer. -->
<script setup lang="ts">
import { HilosPages, formatMoment } from '@hilos/core/base'
import {
  createHilosStatusSubscribersTable,
  createHilosStatusSubscribersState,
  createHilosStatusSubscriberController,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  STATUS_SUBSCRIBER_EMAIL_FIELD,
  STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD,
  STATUS_SUBSCRIBER_CONFIRMED_FIELD,
  type HilosStatusSubscribersContext,
} from '@hilos/core/admin/status'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosHideable from '../../HilosHideable.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { useSignal } from '../../useSignal.js'
import HilosStatusRemoveSubscriberModal from './HilosStatusRemoveSubscriberModal.vue'

const props = defineProps<{
  /** The project's existing scope, connection and action lifecycle. */
  context: HilosStatusSubscribersContext
}>()
const table = createHilosStatusSubscribersTable(props.context)
const state = createHilosStatusSubscribersState(props.context)
const controller = createHilosStatusSubscriberController(props.context, table)
const counts = useSignal(state.counts)
const personalControlsReason = useSignal(table.personalControlsReason)
onMounted(() => {
  table.start()
  state.start()
  controller.start()
})
onUnmounted(() => {
  controller.dispose()
  state.dispose()
  table.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.STATUS_SUBSCRIBERS"
    head-kind="section"
    tabs-label="Status pages"
  >
    <dl
      class="d-flex flex-wrap gap-3 mb-3"
      data-id="hilos-status-subscriber-counts"
    >
      <div>
        <dt>{{ HILOS_STATUS_SUBSCRIBERS_COPY.confirmed }}</dt>
        <dd class="mb-0" data-id="hilos-status-subscribers-confirmed">
          {{ counts?.confirmedCount ?? '—' }}
        </dd>
      </div>
      <div>
        <dt>{{ HILOS_STATUS_SUBSCRIBERS_COPY.awaitingConfirmation }}</dt>
        <dd class="mb-0" data-id="hilos-status-subscribers-pending">
          {{ counts?.pendingCount ?? '—' }}
        </dd>
      </div>
    </dl>
    <div class="hilos-stack mb-3">
      <p class="invisible mb-0" aria-hidden="true">
        {{ HILOS_STATUS_SUBSCRIBERS_COPY.unavailable }}
      </p>
      <p
        v-if="counts === null"
        class="text-body-secondary mb-0"
        data-id="hilos-status-subscribers-unavailable"
      >
        {{ HILOS_STATUS_SUBSCRIBERS_COPY.unavailable }}
      </p>
    </div>
    <HilosViewportTable
      :controller="table.controller"
      data-id="hilos-status-subscribers-table"
    >
      <template #[`cell-${STATUS_SUBSCRIBER_EMAIL_FIELD}`]="{ row }">
        <span
          class="text-break"
          :data-id="`hilos-status-subscriber-email-${row.id}`"
          ><HilosHideable :value="row.email"
        /></span>
      </template>
      <template #[`cell-${STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD}`]="{ row }">
        {{ formatMoment(row.subscribedAt) ?? '—' }}
      </template>
      <template #[`cell-${STATUS_SUBSCRIBER_CONFIRMED_FIELD}`]="{ row }">
        <span
          :class="row.confirmed ? 'text-success' : 'text-body-secondary'"
          :data-id="`hilos-status-subscriber-confirmed-${row.id}`"
          >{{
            row.confirmed
              ? HILOS_STATUS_SUBSCRIBERS_COPY.yes
              : HILOS_STATUS_SUBSCRIBERS_COPY.awaitingConfirmation
          }}</span
        >
      </template>
      <template #cell-actions="{ row, rowKey }">
        <LoadingButton
          class="btn-outline-danger btn-sm"
          :disabled="personalControlsReason !== null"
          :aria-label="HILOS_STATUS_SUBSCRIBERS_COPY.remove"
          :data-id="`hilos-status-subscriber-remove-${row.id}`"
          @click="controller.open(rowKey)"
          ><i class="bi bi-trash" aria-hidden="true"></i
        ></LoadingButton>
      </template>
    </HilosViewportTable>
    <HilosStatusRemoveSubscriberModal :controller="controller" />
  </HilosAdminPage>
</template>
