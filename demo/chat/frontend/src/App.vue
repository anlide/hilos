<!-- Root view. Besides the title it shows the live Connection-machine state —
an extra connection-status indicator, explicitly allowed by
docs/agents/frontend/core-and-connection.md — and the current user resolved
from the session scope. The two users-table pages (admin_users, hilos_users)
render their read-only table streamed through the page scope; the main page
is the chat itself and shows no table. Real views land on top of this across
step 7. -->
<script setup lang="ts">
import { useConnectionState, useSignal } from '@hilos/vue'

import { connection } from './connection'
import {
  PAGE_ADMIN_USERS,
  PAGE_HILOS_USERS,
  pageForPath,
  usersTableRows,
} from './page'
import { currentUserName } from './session'

const page = pageForPath(location.pathname)
const showUsersTable = page === PAGE_ADMIN_USERS || page === PAGE_HILOS_USERS
const connectionState = useConnectionState(connection)
const selfName = useSignal(currentUserName)
const userRows = useSignal(usersTableRows)
</script>

<template>
  <main data-id="app-root">
    Hilos
    <span data-id="conn-state">{{ connectionState }}</span>
    <span data-id="self-user">{{ selfName }}</span>
    <table v-if="showUsersTable" data-id="users-table">
      <tbody>
        <tr v-for="row in userRows" :key="row.rowKey" data-id="user-row">
          <td>{{ row.name }}</td>
        </tr>
      </tbody>
    </table>
  </main>
</template>
