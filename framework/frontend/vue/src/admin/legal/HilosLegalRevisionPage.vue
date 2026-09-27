<script setup lang="ts">
import {
  computedSignal,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
  HilosPages,
  LEGAL_CATALOG_REFUSAL_SECTION,
  LEGAL_REVISION_SECTION,
  legalAdminRevisionSchema,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosLegalChanges from '../../legal/HilosLegalChanges.vue'
import HilosLegalRevisionText from '../../legal/HilosLegalRevisionText.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext }>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosLegalRevisionPage requires a provided Hilos router.')
const documentKey = computedSignal(() =>
  String(router.currentRoute.get().params.documentKey ?? ''),
)
const raw = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_REVISION_SECTION),
)
const refusal = useSignal(
  props.context.scopes.pageDataSignal(LEGAL_CATALOG_REFUSAL_SECTION),
)
const details = computed(() => {
  const parsed = legalAdminRevisionSchema.safeParse(raw.value)
  return parsed.success ? parsed.data : null
})
const revisionId = computedSignal(() =>
  String(router.currentRoute.get().params.revisionId ?? ''),
)
const revisions = createHilosLegalRevisionsTable(
  props.context,
  documentKey,
  HilosPages.LEGAL_REVISION,
  revisionId,
)
const accepted = useSignal(
  createHilosLegalRevisionAcceptanceSummary(revisions.controller, revisionId),
)
onMounted(() => revisions.start())
onUnmounted(() => revisions.dispose())
</script>

<template>
  <HilosAdminPage :page="HilosPages.LEGAL_REVISION">
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
      <div
        v-if="!details.declared"
        class="alert alert-warning"
        data-id="legal-revision-undeclared"
      >
        This revision is no longer in code. Its text and comparison are
        unavailable.
      </div>
      <template v-else-if="details.revision">
        <p>
          <strong>{{ details.revisionId }}</strong>
          <span v-if="details.current" class="badge text-bg-primary ms-2"
            >Current</span
          >
          <span class="badge text-bg-secondary ms-2">{{
            details.revision.significance
          }}</span>
        </p>
        <p class="text-body-secondary small">
          Published {{ details.revision.publishedOn }} · Effective
          {{ details.revision.effectiveOn }}
        </p>
        <section class="mb-4">
          <h2 class="h5">Changes from the previous revision</h2>
          <HilosLegalChanges
            v-if="details.changes !== null && details.predecessorId !== null"
            :changes="details.changes"
            :from-label="details.predecessorId"
            :to-label="details.revisionId"
          />
          <p v-else class="text-body-secondary">
            This is the first revision; there is nothing to compare it with.
          </p>
        </section>
        <section v-if="details.clauses" class="mb-4">
          <h2 class="h5">Complete text</h2>
          <HilosLegalRevisionText :clauses="details.clauses" />
        </section>
      </template>
      <section>
        <h2 class="h5">Who accepted this revision</h2>
        <p data-id="legal-revision-accepted">
          {{ accepted }}
        </p>
        <HilosLink
          :to="resolveHilosPath(HilosPages.LEGAL_ACCEPTANCES)"
          class="btn btn-outline-secondary"
          data-id="legal-open-acceptances"
          >Open acceptances</HilosLink
        >
      </section>
    </template>
  </HilosAdminPage>
</template>
