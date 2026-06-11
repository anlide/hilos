<!-- Root view. Besides the title it shows the live Connection-machine state —
an extra connection-status indicator, explicitly allowed by
docs/agents/frontend/core-and-connection.md — and the current user resolved
from the session scope. Real views land on top of this from step 7. -->
<script setup lang="ts">
import { useConnectionState, useSignal } from '@hilos/vue'

import { connection } from './connection'
import { currentUserName } from './session'

const connectionState = useConnectionState(connection)
const selfName = useSignal(currentUserName)
</script>

<template>
  <main data-id="app-root">
    Hilos
    <span data-id="conn-state">{{ connectionState }}</span>
    <span data-id="self-user">{{ selfName }}</span>
  </main>
</template>
