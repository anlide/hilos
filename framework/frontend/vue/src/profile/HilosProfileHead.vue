<!-- Keep the selected inputs together for the view-owned store, mirrors and
photo window. Only replacement inputs start a new view lifetime. -->
<script setup lang="ts">
import type {
  HilosProfileHeadStore,
  HilosProfilePageContext,
} from '@hilos/core/base'
import { shallowRef, watch } from 'vue'
import HilosProfileHeadContent from './HilosProfileHeadContent.vue'

const props = defineProps<{
  /** The profile page's connection, scopes and tracked actions. */
  context: HilosProfilePageContext
  /** A started store borrowed from the root; its caller owns its lifetime. */
  store?: HilosProfileHeadStore
}>()
const lifetime = shallowRef(0)
watch([() => props.context, () => props.store], () => {
  lifetime.value += 1
})
</script>

<template>
  <HilosProfileHeadContent :key="lifetime" :context="context" :store="store" />
</template>
