<script setup lang="ts">
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  HilosPages,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_DOCUMENT_SECTION,
  legalAdminDocumentSchema,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosViewportTable from '../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosLegalDocumentPage requires a provided Hilos router.')
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const document = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_DOCUMENT_SECTION),
)
const refusal = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
)
const details = computed(() => {
  const parsed = legalAdminDocumentSchema.safeParse(document.value)
  return parsed.success ? parsed.data : null
})
const revisions = createHilosLegalRevisionsTable(props.context, documentKey)
onMounted(() => revisions.start())
onUnmounted(() => revisions.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.LEGAL_DOCUMENT">
    <div
      v-if="typeof refusal === 'string'"
      class="alert alert-danger text-break"
      role="alert"
      data-id="legal-catalog-refusal"
    >
      <h2 class="h6">The legal document catalog could not be loaded</h2>
      <p class="mb-0">{{ refusal }}</p>
    </div>
    <template v-else-if="details">
      <div class="alert alert-secondary small">
        Document texts live in code. A new revision is published by a
        deployment.
      </div>
      <section v-if="details.set" data-id="legal-set" class="mb-4">
        <h2 class="h5">Hilos standard set {{ details.set.version }}</h2>
        <p class="small text-body-secondary">
          {{ details.set.publishedOn }} · {{ details.set.significance }} ·
          {{ details.set.clauses.length }} clauses
        </p>
        <ol class="list-group list-group-numbered">
          <li
            v-for="clause in details.set.clauses"
            :key="clause.clauseKey"
            class="list-group-item text-break"
          >
            <code class="small me-2">{{ clause.clauseKey }}</code
            >{{ clause.statement }}
          </li>
        </ol>
      </section>
      <section
        v-if="details.newerSet"
        class="alert alert-warning"
        data-id="legal-set-newer"
      >
        <h2 class="h6">
          Hilos has released set {{ details.newerSet.version }} for this
          document
        </h2>
        <p class="small">
          {{ details.newerSet.publishedOn }} ·
          {{ details.newerSet.significance }}. The project keeps its declared
          set until it publishes a new revision.
        </p>
        <ul class="mb-0 small">
          <li
            v-for="change in details.newerSet.changes"
            :key="change.clauseKey"
          >
            {{ change.kind }}: {{ change.title }}
            <code>{{ change.clauseKey }}</code>
          </li>
        </ul>
      </section>
      <section class="mb-4">
        <h2 class="h5">Different in this project</h2>
        <p v-if="!details.deviations.length" class="text-body-secondary">
          No project deviations are declared.
        </p>
        <div
          v-for="deviation in details.deviations"
          :key="deviation.clauseKey"
          class="border rounded p-3 mb-2 text-break"
          data-id="legal-deviation-row"
        >
          <h3 class="h6">
            {{ deviation.statement }}
            <span class="badge text-bg-secondary">{{
              deviation.direction
            }}</span>
          </h3>
          <p class="small text-body-secondary">
            Standard: {{ deviation.standardStatement }} ·
            <code>{{ deviation.clauseKey }}</code>
          </p>
          <p
            v-for="(paragraph, index) in deviation.text.split('\n\n')"
            :key="index"
            class="small mb-2"
          >
            {{ paragraph }}
          </p>
        </div>
      </section>
      <HilosViewportTable :controller="revisions.controller">
        <template #cell-rowKey="{ row }">
          <div data-id="legal-revision-row" :data-revision="row.rowKey">
            <strong>{{ row.rowKey }}</strong>
            <span v-if="row.current" class="badge text-bg-primary ms-2"
              >Current</span
            >
            <span v-if="!row.declared" class="badge text-bg-warning ms-2"
              >Not in code</span
            >
            <div v-if="row.revision" class="small text-body-secondary">
              {{ row.revision.publishedOn }} · {{ row.revision.significance }} ·
              Effective {{ row.revision.effectiveOn }} · Set
              {{ row.revision.setVersion }}
            </div>
          </div>
        </template>
        <template #cell-actions="{ row }"
          ><HilosLink
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
          ></template
        >
      </HilosViewportTable>
    </template>
  </HilosAdminPage>
</template>
