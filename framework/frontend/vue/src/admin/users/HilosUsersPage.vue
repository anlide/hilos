<!-- HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
users table inside the admin shell. All table logic and the row view-model are
the core headless's (createHilosUsersTable / HilosUserRow); this view owns only
the column set and the cell markup, so a project mounts it by passing its
HilosUsersContext. The framework owns every cell except the trailing actions
cell, which a project fills through the `#row-actions` slot (e.g. a link to the
detail page). Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import {
  createHilosUsersTable,
  HilosPages,
  type HilosTableColumn,
  type HilosUserRow,
  type HilosUsersContext,
} from '@hilos/core'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosTable from '../../HilosTable.vue'

const props = defineProps<{
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
}>()

defineSlots<{
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  'row-actions'(props: { row: HilosUserRow }): unknown
}>()

const usersTable = createHilosUsersTable(props.context)

const columns: HilosTableColumn[] = [
  { key: 'id', label: 'ID', sortable: true },
  { key: 'name', label: 'Name', sortable: true },
  { key: 'presence', label: 'Presence', sortable: true },
  {
    key: 'onlineSessionCount',
    label: 'Sessions',
    sortable: true,
    headerClass: 'text-end',
  },
  { key: 'lastActivity', label: 'Last activity', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]
</script>

<template>
  <HilosAdminPage :page="HilosPages.USERS">
    <HilosTable
      :controller="usersTable"
      :columns="columns"
      searchable
      search-placeholder="Search users…"
      empty-text="No users yet."
    >
      <template #row="{ row }">
        <td class="text-body-secondary">{{ row.id }}</td>
        <td class="fw-medium">{{ row.name }}</td>
        <td>
          <span
            class="badge"
            :class="
              row.presence === 'online'
                ? 'text-bg-success'
                : 'text-bg-secondary'
            "
            >{{ row.presence }}</span
          >
        </td>
        <td class="text-end">{{ row.onlineSessionCount }}</td>
        <td>{{ row.lastActivity ?? '—' }}</td>
        <td class="text-end"><slot name="row-actions" :row="row" /></td>
      </template>
    </HilosTable>
  </HilosAdminPage>
</template>
