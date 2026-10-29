<!-- The standard viewport owns search, order, facets, Apply and narrow cards. -->
<script setup lang="ts">
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusWorksTable,
  createHilosStatusWorksState,
  createHilosStatusWorkCreate,
  createHilosStatusWorkEdit,
  createHilosStatusWorkCancel,
  HILOS_STATUS_WORK_ACTION_COPY,
  STATUS_WORK_STARTS_AT_FIELD,
  STATUS_WORK_ENDS_AT_FIELD,
  STATUS_WORK_TITLE_FIELD,
  STATUS_WORK_COMPONENT_KEYS_FIELD,
  STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD,
  type HilosStatusWorksContext,
} from '@hilos/core/admin/status'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { useSignal } from '../../useSignal.js'
import HilosStatusWorkModal from './HilosStatusWorkModal.vue'
import HilosStatusCancelWorkModal from './HilosStatusCancelWorkModal.vue'

const props = defineProps<{
  /** The project's existing scopes, connection and tracked actions. */
  context: HilosStatusWorksContext
}>()
const state = createHilosStatusWorksState(props.context)
const table = createHilosStatusWorksTable(props.context, {
  openSchedule: () => {
    if (!dialogOpen()) create.open()
  },
})
const create = createHilosStatusWorkCreate(props.context, state)
const edit = createHilosStatusWorkEdit(props.context, table, state)
const cancel = createHilosStatusWorkCancel(props.context, table)
const data = useSignal(state.data)
const timeZone = useSignal(hilosTimeZone)
const opened = useSignal({ get: dialogOpen })

/** One table focus belongs to one dialog; opening again never drops a draft. */
function dialogOpen(): boolean {
  return create.opened.get() || edit.opened.get() || cancel.opened.get()
}

function moment(at: number | null): string {
  void timeZone.value
  if (at === null) return '—'
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

function componentNames(keys: readonly string[]): string {
  return keys
    .map(
      (key) =>
        data.value?.components.find(
          (component) => component.componentKey === key,
        )?.name ?? key,
    )
    .join(', ')
}

onMounted(() => {
  state.start()
  table.start()
  create.start()
  edit.start()
  cancel.start()
})
onUnmounted(() => {
  cancel.dispose()
  edit.dispose()
  create.dispose()
  table.dispose()
  state.dispose()
})
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.STATUS_WORKS"
    head-kind="section"
    tabs-label="Status pages"
  >
    <div class="hilos-stack mb-3">
      <p class="invisible mb-0" aria-hidden="true" inert>
        {{ HILOS_STATUS_WORK_ACTION_COPY.unavailable }}
      </p>
      <p
        v-if="data === null"
        class="text-body-secondary mb-0"
        data-id="hilos-status-works-unavailable"
      >
        {{ HILOS_STATUS_WORK_ACTION_COPY.unavailable }}
      </p>
    </div>
    <HilosViewportTable
      v-if="data !== null"
      :controller="table.controller"
      data-id="hilos-status-works-table"
    >
      <template #[`cell-${STATUS_WORK_STARTS_AT_FIELD}`]="{ row }">{{
        moment(row.startsAt)
      }}</template>
      <template #[`cell-${STATUS_WORK_ENDS_AT_FIELD}`]="{ row }">{{
        moment(row.endsAt)
      }}</template>
      <template #[`cell-${STATUS_WORK_TITLE_FIELD}`]="{ row }">
        <span
          class="text-break"
          :data-id="`hilos-status-work-title-${row.id}`"
          >{{ row.title }}</span
        >
        <p
          v-if="row.description"
          class="small text-body-secondary mb-0 text-break"
        >
          {{ row.description }}
        </p>
      </template>
      <template #[`cell-${STATUS_WORK_COMPONENT_KEYS_FIELD}`]="{ row }">{{
        componentNames(row.componentKeys)
      }}</template>
      <template #[`cell-${STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD}`]="{ row }">
        <span :data-id="`hilos-status-work-mail-${row.id}`">{{
          moment(row.lastMailQueuedAt)
        }}</span>
      </template>
      <template #cell-actions="{ row, rowKey }">
        <div class="d-flex gap-2">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :aria-label="`Change scheduled maintenance · ${row.title}`"
            :data-id="`hilos-status-work-edit-${row.id}`"
            @click="!opened && edit.open(rowKey)"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :aria-label="`Cancel scheduled maintenance · ${row.title}`"
            :data-id="`hilos-status-work-cancel-${row.id}`"
            @click="!opened && cancel.open(rowKey)"
          >
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
      </template>
    </HilosViewportTable>
    <HilosStatusWorkModal :controller="create" :state="state" />
    <HilosStatusWorkModal :controller="edit" :state="state" />
    <HilosStatusCancelWorkModal :controller="cancel" />
  </HilosAdminPage>
</template>
