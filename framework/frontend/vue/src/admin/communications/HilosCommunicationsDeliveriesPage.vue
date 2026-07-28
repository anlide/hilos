<!-- HilosCommunicationsDeliveriesPage — the framework Hilos delivery-logs page
(HilosPages.COMMUNICATIONS_DELIVERIES): the admin journal of channel deliveries
inside the admin shell. The journal is served straight from SQL (an unbounded
table), so it has no live per-row deltas — a status / period filter or a retry
re-requests the window. The per-channel route ({channelId}) opens the otherwise
cross-cutting journal with a channel preset; the status picker, the period range,
and the type/recipient search ride the open viewport filter map (server-side, no
local filtering). The single row action is retry, shown only on a failed delivery:
it re-queues the delivery as a tracked action (createHilosDeliveriesActions) and
refreshes the window. All table logic and the row view-model are the core
headless's (hilosDeliveries); this view owns only the markup, so a project mounts
it by passing its HilosDeliveriesContext. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  DELIVERY_FILTER_FROM,
  DELIVERY_FILTER_STATUS,
  DELIVERY_FILTER_TO,
  HILOS_DELIVERY_STATUSES,
  HilosPages,
  isDeliveryRetryable,
  type HilosDeliveriesContext,
  type HilosDeliveryRow,
  type HilosTableColumn,
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

const columns: HilosTableColumn[] = [
  { key: 'createdAt', label: 'Date', sortable: true },
  { key: 'channel', label: 'Channel', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  {
    key: 'attempts',
    label: 'Attempts',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'deliveredAt', label: 'Delivered', sortable: true },
  { key: 'recipient', label: 'Recipient' },
  { key: 'notification', label: 'Notification' },
  { key: 'lastError', label: 'Error' },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

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

// Domain filters: status and the created_at period ride the open filter map so
// the backend narrows the window (no local filtering). Empty clears the filter.
const statusFilter = ref('')
const fromFilter = ref('')
const toFilter = ref('')

function onStatus(event: Event): void {
  statusFilter.value = (event.target as HTMLSelectElement).value
  deliveriesTable.setFilter(DELIVERY_FILTER_STATUS, statusFilter.value)
}

function onFrom(event: Event): void {
  fromFilter.value = (event.target as HTMLInputElement).value
  deliveriesTable.setFilter(DELIVERY_FILTER_FROM, fromFilter.value)
}

function onTo(event: Event): void {
  toFilter.value = (event.target as HTMLInputElement).value
  deliveriesTable.setFilter(DELIVERY_FILTER_TO, toFilter.value)
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
    deliveriesTable.start()
  }
  retryPendingId.value = null
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.COMMUNICATIONS_DELIVERIES">
    <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
      <div v-if="channel">
        <span class="form-label d-block">Channel</span>
        <span
          class="badge text-bg-secondary-subtle text-secondary-emphasis fs-6"
        >
          <code>{{ channel }}</code>
        </span>
      </div>
      <div>
        <label class="form-label" for="hilos-delivery-status">Status</label>
        <select
          id="hilos-delivery-status"
          class="form-select"
          :value="statusFilter"
          data-id="hilos-delivery-status"
          @change="onStatus"
        >
          <option
            v-for="option in HILOS_DELIVERY_STATUSES"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </option>
        </select>
      </div>
      <div>
        <label class="form-label" for="hilos-delivery-from">From</label>
        <input
          id="hilos-delivery-from"
          type="date"
          class="form-control"
          :value="fromFilter"
          data-id="hilos-delivery-from"
          @change="onFrom"
        />
      </div>
      <div>
        <label class="form-label" for="hilos-delivery-to">To</label>
        <input
          id="hilos-delivery-to"
          type="date"
          class="form-control"
          :value="toFilter"
          data-id="hilos-delivery-to"
          @change="onTo"
        />
      </div>
    </div>

    <HilosViewportTable
      label="Deliveries"
      :controller="deliveriesTable"
      :columns="columns"
      searchable
      search-placeholder="Search type or recipient…"
      empty-text="No deliveries match."
    >
      <template #row="{ row }">
        <td class="text-nowrap">{{ row.createdAt || '—' }}</td>
        <td>
          <code>{{ row.channel || '—' }}</code>
        </td>
        <td>
          <span class="badge" :class="statusClass(row.status)">{{
            row.status || '—'
          }}</span>
        </td>
        <td class="text-end">{{ row.attempts }}</td>
        <td class="text-nowrap">{{ row.deliveredAt || '—' }}</td>
        <td>{{ recipientLabel(row) }}</td>
        <td>
          <div class="fw-semibold">{{ row.notificationTitle || '—' }}</div>
          <code class="small text-body-secondary">{{
            row.notificationType
          }}</code>
        </td>
        <td class="text-body-secondary">{{ row.lastError || '—' }}</td>
        <td class="text-end">
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
        </td>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
