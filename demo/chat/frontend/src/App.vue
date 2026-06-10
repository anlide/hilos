<!-- Root view. Besides the title it shows the live Connection-machine state —
an extra connection-status indicator, explicitly allowed by
docs/agents/frontend/core-and-connection.md. Real views land on top of this
from step 7. -->
<script setup lang="ts">
import { useConnectionState } from '@hilos/vue'

import { connection } from './connection'

const connectionState = useConnectionState(connection)
</script>

<template>
  <main data-id="app-root">
    Hilos
    <span data-id="conn-state">{{ connectionState }}</span>
  </main>
</template>
