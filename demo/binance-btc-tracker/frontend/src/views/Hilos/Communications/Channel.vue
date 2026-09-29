<!-- The Hilos channel-config page (HilosPages.COMMUNICATIONS_CHANNEL): a thin
project binding of the framework HilosCommunicationsChannelPage to this app's
context. The fields table, the value/source projection, and the set / reset /
test round-trips are the framework's; the project supplies only the context.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosCommunicationsChannelPage } from '@hilos/vue'

import { hilosCommunicationsContext } from './hilosCommunicationsContext'

defineOptions({ name: 'CommunicationsChannelPage' })
</script>

<template>
  <HilosCommunicationsChannelPage :context="hilosCommunicationsContext" />
</template>
