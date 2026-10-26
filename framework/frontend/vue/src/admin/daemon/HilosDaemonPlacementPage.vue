<script setup lang="ts">
import {
  createHilosDaemonPlacementView,
  readHilosDaemonOverview,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { HilosPages } from '@hilos/core/base'
import { onMounted, onUnmounted } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import { useSignal } from '../../useSignal.js'
import HilosDaemonPlacement from './HilosDaemonPlacement.vue'

const props = defineProps<{ context: HilosDaemonContext }>()
const overviewSignal = readHilosDaemonOverview(props.context.scopes)
const placement = createHilosDaemonPlacementView(props.context, overviewSignal)
const overview = useSignal(overviewSignal)

onMounted(() => placement.start())
onUnmounted(() => placement.dispose())
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.DAEMON_PLACEMENT"
    head-kind="section"
    tabs-label="Daemon pages"
  >
    <HilosDaemonPlacement :view="placement" :overview="overview" />
  </HilosAdminPage>
</template>
