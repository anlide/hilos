<!-- HilosBackupPage — the framework Hilos backup page (HilosPages.BACKUP): the
stored-backup list inside the admin shell. Read-only and live — the rows arrive
over the socket from the backup runtime index plus the single in-progress backup,
so an in-progress row shows an indeterminate progress bar until it completes and
merges into the index. All table logic and the row view-model are the core
headless's (createHilosBackupsTable / HilosBackupRow); this view owns only the
column set and the cell markup, so a project mounts it by passing its
HilosBackupsContext. Row actions (create / delete / keep) are a separate page
(HIL-333). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosBackupsTable,
  HilosPages,
  type HilosBackupRow,
  type HilosBackupsContext,
  type HilosTableColumn,
} from '@hilos/core'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'

const props = defineProps<{
  /** The project context: scope stores and the live connection. */
  context: HilosBackupsContext
}>()

const backups = createHilosBackupsTable(props.context)
const backupsTable = backups.controller

// Bind the server-windowed table to the connection on mount, request the first
// window, and unbind on unmount.
onMounted(() => backups.start())
onUnmounted(() => backups.dispose())

const columns: HilosTableColumn[] = [
  { key: 'createdAt', label: 'Date', sortable: true },
  { key: 'env', label: 'Environment', sortable: true },
  { key: 'scope', label: 'Scope', sortable: true },
  { key: 'sizeBytes', label: 'Size', sortable: true, headerClass: 'text-end' },
  {
    key: 'durationSeconds',
    label: 'Duration',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'status', label: 'Status', sortable: true },
]

/** Whether the backup is the single in-progress row (renders a live progress bar). */
function isRunning(row: HilosBackupRow): boolean {
  return row.finished === false
}

/** Human-readable archive size; an in-progress backup has no size yet. */
function formatSize(row: HilosBackupRow): string {
  if (isRunning(row) || row.sizeBytes <= 0) {
    return '—'
  }
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = row.sizeBytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

/** Human-readable capture duration; an in-progress backup has no duration yet. */
function formatDuration(row: HilosBackupRow): string {
  if (isRunning(row) || row.durationSeconds <= 0) {
    return '—'
  }
  const seconds = row.durationSeconds
  if (seconds < 60) {
    return `${seconds}s`
  }

  return `${Math.floor(seconds / 60)}m ${seconds % 60}s`
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.BACKUP">
    <HilosViewportTable
      label="Backups"
      :controller="backupsTable"
      :columns="columns"
      searchable
      search-placeholder="Search backups…"
      empty-text="No backups yet."
    >
      <template #row="{ row }">
        <td class="text-nowrap">
          {{ row.createdAt || '—' }}
          <span
            v-if="row.keep"
            class="badge text-bg-warning ms-1"
            title="Pinned out of rotation"
            >keep</span
          >
        </td>
        <td>{{ row.env || '—' }}</td>
        <td><code>{{ row.scope || '—' }}</code></td>
        <td class="text-end">{{ formatSize(row) }}</td>
        <td class="text-end">{{ formatDuration(row) }}</td>
        <td style="min-width: 10rem">
          <div v-if="isRunning(row)" class="progress" role="status">
            <div
              class="progress-bar progress-bar-striped progress-bar-animated"
              style="width: 100%"
            >
              In progress
            </div>
          </div>
          <span
            v-else-if="row.finished === true"
            class="badge text-bg-success"
            >{{ row.status || 'success' }}</span
          >
          <span v-else class="badge text-bg-danger">{{
            row.status || 'error'
          }}</span>
        </td>
      </template>
    </HilosViewportTable>
  </HilosAdminPage>
</template>
