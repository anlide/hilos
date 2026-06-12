<!-- Root view. The application shell is the SDK's HilosLayout; the demo fills
its brand and content slots. The live connection state is the shell's own
indicator (an extra status surface explicitly allowed by
docs/agents/frontend/core-and-connection.md); the current user resolved from the
session scope renders in the content slot. Real views land here from step 7. -->
<script setup lang="ts">
import { HilosLayout, useSignal } from '@hilos/vue'

import { connection } from './connection'
import { currentUserName } from './session'

const selfName = useSignal(currentUserName)
</script>

<template>
  <HilosLayout :connection="connection">
    <template #brand>Hilos Chat</template>
    <span data-id="self-user">{{ selfName }}</span>
  </HilosLayout>
</template>
