<!-- The home page (PAGE_MAIN). For now it says who is looking and nothing more;
the chart this page becomes arrives with its own leaf (HIL-159). Two branches,
because a visitor is not a user: with an account the line names the account from
the session scope, without one it says the visit is anonymous — the demo hands a
guest no name, so the `self-user` marker is absent rather than empty. Rendered by
HilosView when the navigator's route is the main page. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useSignal } from '@hilos/vue'

import { currentUserId, currentUserName } from '../../bootstrap/session'

defineOptions({ name: 'MainPage' })

const selfName = useSignal(currentUserName)
const selfId = useSignal(currentUserId)
// What decides the branch is whether the session names a user, never whether a
// name string came out empty: the id and the name are read off one session-scope
// ref, so an absent ref gives a null id and an empty name together.
const isAuthenticated = computed(() => selfId.value !== null)
</script>

<template>
  <h1 class="visually-hidden">BTC Tracker</h1>
  <p>
    <template v-if="isAuthenticated">
      Signed in as <span data-id="self-user">{{ selfName }}</span>
      <span data-id="self-user-id" hidden>{{ selfId }}</span>
    </template>
    <template v-else>
      <span data-id="self-anonymous">Browsing anonymously</span>
    </template>
  </p>
</template>
