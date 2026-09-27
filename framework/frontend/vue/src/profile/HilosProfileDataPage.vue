<script setup lang="ts">
import { onMounted, onUnmounted, shallowRef } from 'vue'
import {
  createHilosProfileDataExport,
  HILOS_DATA_EXPORT_COPY,
  type HilosDataExportContext,
} from '@hilos/core'
import HilosPageHeading from '../HilosPageHeading.vue'
import HilosDataExport from './HilosDataExport.vue'

const props = defineProps<{ context: HilosDataExportContext }>()
const copy = shallowRef<ReturnType<typeof createHilosProfileDataExport> | null>(
  null,
)
onMounted(() => {
  copy.value = createHilosProfileDataExport(props.context)
  copy.value.store.start()
})
onUnmounted(() => {
  copy.value?.flow.dispose()
  copy.value?.store.dispose()
})
</script>

<template>
  <section data-id="profile-data-view">
    <HilosPageHeading />
    <HilosDataExport
      v-if="copy"
      :store="copy.store"
      :flow="copy.flow"
      :lead="HILOS_DATA_EXPORT_COPY.sectionLead"
      :titled="false"
    />
  </section>
</template>
