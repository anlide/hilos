<!-- The home page (PAGE_MAIN). Empty to the eye, for a visitor and for a person
signed in alike: the product this page becomes is not built yet. The first-level
heading stays for the screen reader, and a signed-in session leaves two hidden
markers — the account's name and id from the session scope — that a scenario
reads to know who is looking. A visitor is not a user, so a guest gets neither
marker rather than empty ones. Rendered by HilosView when the navigator's route
is the main page. -->
<script setup lang="ts">
import { computed } from 'vue'
import { useSignal } from '@hilos/vue/base'

import { currentUserId, currentUserName } from '../../bootstrap/session.js'

defineOptions({ name: 'MainPage' })

const selfName = useSignal(currentUserName)
const selfId = useSignal(currentUserId)
// What decides the branch is whether the session names a user, never whether a
// name string came out empty: the id and the name are read off one session-scope
// ref, so an absent ref gives a null id and an empty name together.
const isAuthenticated = computed(() => selfId.value !== null)
</script>

<template>
  <h1 class="visually-hidden">Roblox Game Server</h1>
  <template v-if="isAuthenticated">
    <span class="visually-hidden" data-id="self-user">{{ selfName }}</span>
    <span data-id="self-user-id" hidden>{{ selfId }}</span>
  </template>
</template>
