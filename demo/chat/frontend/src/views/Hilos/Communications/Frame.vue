<!-- Thin project binding of the SDK channel frame page to this app's context. -->
<script setup lang="ts">
import { HilosCommunicationsChannelFramePage } from '@hilos/vue/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

defineOptions({ name: 'CommunicationsChannelFramePage' })
</script>

<template>
  <HilosCommunicationsChannelFramePage :context="hilosCommunicationsContext" />
</template>
