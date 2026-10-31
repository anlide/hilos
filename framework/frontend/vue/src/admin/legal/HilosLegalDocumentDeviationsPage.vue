<script setup lang="ts">
import { createHilosLegalDocumentDeviationsTable } from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'
import HilosLegalDocumentPageShell from './HilosLegalDocumentPageShell.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const props = defineProps<{ context: HilosLegalContext }>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error(
    'HilosLegalDocumentDeviationsPage requires a provided Hilos router.',
  )
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const deviations = createHilosLegalDocumentDeviationsTable(
  props.context,
  documentKey,
)
onMounted(deviations.start)
onUnmounted(() => {
  deviations.dispose()
  deviations.controller.dispose()
})
</script>

<template>
  <HilosLegalDocumentPageShell
    :context="context"
    :page="HilosPages.LEGAL_DOCUMENT_DEVIATIONS"
  >
    <template #default>
      <HilosViewportTable :controller="deviations.controller">
        <template #cell-statement="{ row }">
          <div class="fw-semibold">{{ row.statement }}</div>
          <p class="small text-body-secondary">
            Standard: {{ row.standardStatement }}
          </p>
          <p
            v-for="(paragraph, index) in row.text.split('\n\n')"
            :key="index"
            class="small mb-2"
          >
            {{ paragraph }}
          </p>
        </template>
        <template #cell-direction="{ row }">
          <span v-if="row.direction" class="badge text-bg-secondary">{{
            row.direction
          }}</span>
          <span v-else class="text-body-secondary">—</span>
        </template>
        <template #cell-clauseKey="{ row }"
          ><code>{{ row.clauseKey }}</code></template
        >
      </HilosViewportTable>
    </template>
  </HilosLegalDocumentPageShell>
</template>
