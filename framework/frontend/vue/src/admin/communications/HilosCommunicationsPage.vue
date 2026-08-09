<!-- HilosCommunicationsPage — the framework Hilos communications hub page
(HilosPages.COMMUNICATIONS): the delivery-channels table inside the admin shell.
One row per registered channel (built from the project's channel registry, not a
hardcoded list), showing its enablement toggle, whether it is fully configured,
its transport driver, and a link to its configuration page. The table, the row
view-model, and the enablement round-trip are the core headless's
(createHilosChannelsTable / createHilosCommunicationsActions); this view owns only
the markup, so a project mounts it by passing its HilosCommunicationsContext.
The toggle is a tracked action ("client action = loading + signal, never
fire-forget"): it dispatches the shared set action with the `enabled` field, the
outcome toasts, and the row redraws from the reactive table's snapshot signal —
there is no new server->client signal. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  CHANNEL_ENABLED_FIELD,
  createHilosChannelsTable,
  createHilosCommunicationsActions,
  HilosChannelRowKey,
  HilosPages,
  resolveHilosPath,
  type HilosChannelRow,
  type HilosCommunicationsContext,
  type HilosTableColumnOf,
} from '@hilos/core'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useTrackedAction } from '../../useTrackedAction.js'

const props = defineProps<{
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}>()

const channels = createHilosChannelsTable(props.context)
const channelsTable = channels.controller
const { sendChannelSet } = createHilosCommunicationsActions(props.context)

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => channels.start())
onUnmounted(() => channels.dispose())

// One tracked runner for the enablement toggles: the switch redraws from the
// echoed row, so a single in-flight guard across rows is enough (and the busy
// flag disables every switch while one write is settling).
const { busy: toggleBusy, run: runToggle } = useTrackedAction()

const columns: HilosTableColumnOf<HilosChannelRow>[] = [
  { key: HilosChannelRowKey.channel, label: 'Channel', sortable: true },
  { key: HilosChannelRowKey.enabled, label: 'Enabled' },
  { key: HilosChannelRowKey.configured, label: 'Configured' },
  { key: HilosChannelRowKey.driver, label: 'Driver', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/** The channel's configuration page path (its {channelId} route param is the name). */
function channelPath(row: HilosChannelRow): string {
  return resolveHilosPath(HilosPages.COMMUNICATIONS_CHANNEL, {
    channelId: row.channel,
  })
}

// Dispatch the enablement write as a tracked action; the toggled row redraws from
// the table's snapshot delta, so nothing is set optimistically here.
function toggleEnabled(row: HilosChannelRow, event: Event): void {
  const next = (event.target as HTMLInputElement).checked
  void runToggle(sendChannelSet(row.channel, CHANNEL_ENABLED_FIELD, next))
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.COMMUNICATIONS">
    <HilosViewportTable
      label="Delivery channels"
      :controller="channelsTable"
      :columns="columns"
      searchable
      search-placeholder="Search channels…"
      empty-text="No delivery channels registered."
    >
      <template #row="{ row }">
        <td>
          <div class="fw-semibold">{{ row.label }}</div>
          <code class="small text-body-secondary">{{ row.channel }}</code>
        </td>
        <td>
          <div class="form-check form-switch mb-0">
            <input
              :id="`hilos-channel-enabled-${row.channel}`"
              type="checkbox"
              class="form-check-input"
              role="switch"
              :checked="row.enabled"
              :disabled="toggleBusy"
              :aria-label="`Enable ${row.label}`"
              :data-id="`hilos-channel-enabled-${row.channel}`"
              @change="toggleEnabled(row, $event)"
            />
          </div>
        </td>
        <td>
          <span
            v-if="row.configured"
            class="badge text-bg-success-subtle text-success-emphasis"
          >
            Configured
          </span>
          <span
            v-else
            class="badge text-bg-warning-subtle text-warning-emphasis"
            :title="`${row.missingFields} field(s) not set`"
          >
            {{ row.missingFields }} missing
          </span>
        </td>
        <td>
          <code class="small">{{ row.driver ?? '—' }}</code>
        </td>
        <td class="text-end">
          <HilosLink
            :to="channelPath(row)"
            class="btn btn-sm btn-outline-primary"
            :data-id="`hilos-channel-configure-${row.channel}`"
          >
            Configure
          </HilosLink>
        </td>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
