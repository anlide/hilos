<!-- The Hilos users list page (HilosPages.USERS): the users table inside the
admin shell. The table is the framework HilosTable driven by the page's headless
controller (usersPage.ts); each row links to the user detail page. The shell
(HilosAdminPage) supplies the breadcrumb, heading, and lead. Bootstrap classes
only (styling-rules.md). -->
<script setup lang="ts">
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import {
  HilosAdminPage,
  HilosLink,
  HilosTable,
  type HilosTableColumn,
} from '@hilos/vue'

import { usersTable } from './usersPage'

defineOptions({ name: 'UsersPage' })

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

/** The detail route for one user, from the framework page catalog. */
function userHref(id: number): string {
  return HILOS_PAGE_ROUTES[HilosPages.USER].replace('{userId}', String(id))
}
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
        <td class="text-end">
          <HilosLink
            :to="userHref(row.id)"
            class="btn btn-sm btn-outline-primary"
            :data-id="`hilos-users-open-${row.id}`"
            >Open</HilosLink
          >
        </td>
      </template>
    </HilosTable>
  </HilosAdminPage>
</template>
