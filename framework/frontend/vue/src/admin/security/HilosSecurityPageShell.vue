<!-- One Security section head; a tab's own lead follows the shared row.
Legacy owners keep the lead already drawn by HilosAdminPage. -->
<script setup lang="ts">
import { HilosPages } from '@hilos/core/base'
import { computed, inject } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The Security page whose body this shell holds. */
  page: string
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosSecurityPageShell requires a provided Hilos router.')
}
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const lead = computed(() =>
  props.page !== HilosPages.SECURITY &&
  route.value.page === props.page &&
  identity.value?.pageHeadOwner?.page === HilosPages.SECURITY
    ? identity.value.lead
    : undefined,
)
</script>

<template>
  <HilosAdminPage
    :page="page"
    head-kind="section"
    tabs-label="Security Center pages"
  >
    <p v-if="lead" class="small text-body-secondary mb-3">{{ lead }}</p>
    <slot />
  </HilosAdminPage>
</template>
