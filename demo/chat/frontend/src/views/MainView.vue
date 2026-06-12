<!-- The chat main page (PAGE_MAIN). For now it shows the session's current user
resolved from the session scope; the real chat views land here later. Rendered
by HilosView when the navigator's route is the main page. -->
<script setup lang="ts">
import { useSignal } from '@hilos/vue'

import { currentUserName } from '../session'

const selfName = useSignal(currentUserName)
</script>

<template>
  <p>Signed in as <span data-id="self-user">{{ selfName }}</span></p>
</template>
