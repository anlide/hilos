<script setup lang="ts">
import {
  createHilosLegalDocument,
  createHilosLegalDocumentSetTable,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'
import HilosLegalDocumentPageShell from './HilosLegalDocumentPageShell.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const details = useSignal(createHilosLegalDocument(props.context))
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosLegalDocumentSetPage requires a provided Hilos router.')
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const clauses = createHilosLegalDocumentSetTable(props.context, documentKey)
onMounted(clauses.start)
onUnmounted(() => {
  clauses.dispose()
  clauses.controller.dispose()
})
</script>

<template>
  <HilosLegalDocumentPageShell
    :context="context"
    :page="HilosPages.LEGAL_DOCUMENT_SET"
  >
    <template v-if="details">
      <p
        v-if="details.set"
        class="small text-body-secondary"
        data-id="legal-set-metadata"
      >
        Set {{ details.set.version }} from {{ details.set.publishedOn }} ·
        {{ details.set.significance }}
      </p>
      <HilosViewportTable :controller="clauses.controller">
        <template #cell-clauseKey="{ row }"
          ><code>{{ row.clauseKey }}</code></template
        >
        <template #cell-statement="{ row }">{{ row.statement }}</template>
      </HilosViewportTable>
    </template>
  </HilosLegalDocumentPageShell>
</template>
