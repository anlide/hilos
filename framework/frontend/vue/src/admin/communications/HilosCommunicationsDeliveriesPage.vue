<!-- HilosCommunicationsDeliveriesPage — the framework Hilos delivery-logs page
(HilosPages.COMMUNICATIONS_DELIVERIES): the admin journal of channel deliveries
inside the admin shell. The journal is served straight from SQL (an unbounded
table), so it has no live per-row deltas — a status / period filter or a retry
re-requests the window. The per-channel route ({channelId}) opens the otherwise
cross-cutting journal with a channel preset; the status picker, the period range,
and the type/recipient search are what the table declares about its frame, drawn
by the framework's bar, and ride the open viewport filter map (server-side, no
local filtering). The single row action is retry, shown only on a failed delivery:
it re-queues the delivery as a tracked action (createHilosDeliveriesActions) and
refreshes the window. All table logic, the row view-model, and that declaration
are the core headless's (hilosDeliveries); this view owns only the markup, so a
project mounts it by passing its HilosDeliveriesContext. Bootstrap classes only
(styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  HilosPages,
  isDeliveryRetryable,
  type HilosDeliveriesContext,
  type HilosDeliveryRow,
} from '@hilos/core'
import { inject, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import LoadingButton from '../../LoadingButton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosDeliveriesContext
}>()

const router = inject(hilosRouterKey)
// The per-channel route ({channelId}) opens the journal with a channel preset; a
// router-less mount (none in practice) shows the cross-cutting journal instead.
const channel = useSignal(
  computedSignal(
    () =>
      (router?.currentRoute.get().params.channelId as string | undefined) ?? '',
  ),
)

const deliveries = createHilosDeliveriesTable(
  props.context,
  channel.value ? { channel: channel.value } : undefined,
)
const deliveriesTable = deliveries.controller
const { sendDeliveryRetry } = createHilosDeliveriesActions(props.context)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => deliveries.start())
onUnmounted(() => deliveries.dispose())

// The status contextual badge: failed is danger, sent is success, the rest neutral.
const STATUS_CLASS: Record<string, string> = {
  failed: 'text-bg-danger',
  sent: 'text-bg-success',
  pending: 'text-bg-secondary',
}

function statusClass(status: string): string {
  return STATUS_CLASS[status] ?? 'text-bg-secondary'
}

// The recipient label: the resolved display name, its user id, both, or a dash.
function recipientLabel(row: HilosDeliveryRow): string {
  if (row.userId === null) {
    return '—'
  }
  const id = `#${row.userId}`

  return row.userLabel ? `${row.userLabel} (${id})` : id
}

// Retry: a per-row tracked action on a failed delivery. On success, re-request the
// window — the journal has no live deltas, so the re-queued row only shows after a
// refresh.
const { busy: retryBusy, run: runRetry } = useTrackedAction()
const retryPendingId = ref<string | null>(null)

async function retry(row: HilosDeliveryRow): Promise<void> {
  if (retryBusy.value) {
    return
  }
  retryPendingId.value = row.rowKey
  if (await runRetry(sendDeliveryRetry(Number(row.rowKey)))) {
    deliveriesTable.refresh()
  }
  retryPendingId.value = null
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.COMMUNICATIONS_DELIVERIES">
    <!-- The channel the route opened the journal on is the table's preset, not
    a filter of its bar: it is named here, above the table, and nothing on the
    page offers to take it off. -->
    <div v-if="channel" class="mb-3">
      <span class="form-label d-block">Channel</span>
      <span class="badge text-bg-secondary-subtle text-secondary-emphasis fs-6">
        <code>{{ channel }}</code>
      </span>
    </div>

    <HilosViewportTable :controller="deliveriesTable">
      <template #cell-createdAt="{ row }">{{ row.createdAt || '—' }}</template>
      <template #cell-channel="{ row }">
        <code>{{ row.channel || '—' }}</code>
      </template>
      <template #cell-status="{ row }">
        <span class="badge" :class="statusClass(row.status)">{{
          row.status || '—'
        }}</span>
      </template>
      <template #cell-attempts="{ row }">{{ row.attempts }}</template>
      <template #cell-deliveredAt="{ row }">{{
        row.deliveredAt || '—'
      }}</template>
      <template #cell-userLabel="{ row }">{{ recipientLabel(row) }}</template>
      <template #cell-actions="{ row }">
        <LoadingButton
          v-if="isDeliveryRetryable(row)"
          class="btn-outline-primary btn-sm"
          :loading="retryBusy && retryPendingId === row.rowKey"
          :disabled="retryBusy"
          :data-id="`hilos-delivery-retry-${row.rowKey}`"
          @click="retry(row)"
        >
          Retry
        </LoadingButton>
      </template>
      <template #detail-notificationTitle="{ row }">
        <div class="fw-semibold">{{ row.notificationTitle || '—' }}</div>
        <code class="small text-body-secondary">{{
          row.notificationType
        }}</code>
      </template>
      <template #detail-lastError="{ row }">
        {{ row.lastError || '—' }}
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
