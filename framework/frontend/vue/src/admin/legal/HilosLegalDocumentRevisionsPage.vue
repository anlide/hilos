<script setup lang="ts">
import {
  createHilosLegalDocument,
  createHilosLegalRevisionsTable,
} from '@hilos/core/admin/legal'
import {
  computedSignal,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core/base'
import { inject, onMounted, onUnmounted } from 'vue'
import HilosLegalDocumentPageShell from './HilosLegalDocumentPageShell.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import HilosLink from '../../HilosLink.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const details = useSignal(createHilosLegalDocument(props.context))
const router = inject(hilosRouterKey)
if (!router)
  throw new Error(
    'HilosLegalDocumentRevisionsPage requires a provided Hilos router.',
  )
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const revisions = createHilosLegalRevisionsTable(
  props.context,
  documentKey,
  HilosPages.LEGAL_DOCUMENT_REVISIONS,
)
onMounted(revisions.start)
onUnmounted(() => {
  revisions.dispose()
  revisions.controller.dispose()
})
</script>

<template>
  <HilosLegalDocumentPageShell
    :context="context"
    :page="HilosPages.LEGAL_DOCUMENT_REVISIONS"
  >
    <template v-if="details">
      <HilosViewportTable :controller="revisions.controller">
        <template #cell-publishedOn="{ row }">
          <div class="fw-semibold">{{ row.publishedOn ?? '—' }}</div>
          <div class="small text-body-secondary">{{ row.rowKey }}</div>
          <span v-if="row.current" class="badge text-bg-primary">Current</span>
          <span v-if="!row.declared" class="badge text-bg-warning"
            >Not in code</span
          >
        </template>
        <template #cell-significance="{ row }">{{
          row.significance ?? '—'
        }}</template>
        <template #cell-effectiveOn="{ row }">{{
          row.effectiveOn ?? '—'
        }}</template>
        <template #cell-setVersion="{ row }">{{
          row.setVersion ?? '—'
        }}</template>
        <template #cell-heldCount="{ row }">{{ row.heldCount }}</template>
        <template #cell-actions="{ row }">
          <HilosLink
            :to="
              resolveHilosPath(HilosPages.LEGAL_REVISION, {
                documentKey: details.document,
                revisionId: row.rowKey,
              })
            "
            class="btn btn-sm btn-outline-secondary"
            data-id="legal-revision-open"
            :data-revision="row.rowKey"
            >Open</HilosLink
          >
        </template>
      </HilosViewportTable>
    </template>
  </HilosLegalDocumentPageShell>
</template>
