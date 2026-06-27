<!-- The Hilos users list page (HilosPages.USERS): a thin project binding of the
framework HilosUsersPage to this app's context. The table, the row view-model,
and search/sort/paging are the framework's; the project supplies the context and
fills the trailing actions cell through the `#row-actions` slot — here, a link to
the user detail page. Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import { HilosLink, HilosUsersPage } from '@hilos/vue'

import { hilosUsersContext } from './hilosUsersContext'

defineOptions({ name: 'UsersPage' })

/** The detail route for one user, from the framework page catalog. */
function userHref(id: number): string {
  return HILOS_PAGE_ROUTES[HilosPages.USER].replace('{userId}', String(id))
}
</script>

<template>
  <HilosUsersPage :context="hilosUsersContext">
    <template #row-actions="{ row }">
      <HilosLink
        :to="userHref(row.id)"
        class="btn btn-sm btn-outline-primary"
        :data-id="`hilos-users-open-${row.id}`"
        >Open</HilosLink
      >
    </template>
  </HilosUsersPage>
</template>
