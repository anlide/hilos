<!-- Thin project binding of the SDK channel configuration page to this app's context. -->
<script setup lang="ts">
import { HilosCommunicationsChannelConfigurationPage } from '@hilos/vue/admin/communications'

import { hilosCommunicationsContext } from './hilosCommunicationsContext.js'

defineOptions({ name: 'CommunicationsChannelConfigurationPage' })
</script>

<template>
  <HilosCommunicationsChannelConfigurationPage
    :context="hilosCommunicationsContext"
  />
</template>
