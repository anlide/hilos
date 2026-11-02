<script setup lang="ts">
import {
  createHilosLegalRevision,
  LEGAL_CATALOG_REFUSAL_SECTION,
} from '@hilos/core/admin/legal'
import {
  bindPageInstanceName,
  computedSignal,
  hilosLegalDocumentLabel,
  HilosPages,
  type HilosLegalContext,
} from '@hilos/core/base'
import { onUnmounted } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{ context: HilosLegalContext; page: string }>()
defineSlots<{
  default?(): unknown
}>()
const revisionSignal = createHilosLegalRevision(props.context)
const refusalSignal = props.context.scopes.pageDataSignal(
  LEGAL_CATALOG_REFUSAL_SECTION,
)
const refusal = useSignal(refusalSignal)
const currentRevision = computedSignal(() =>
  props.context.scopes.page()?.key === props.page &&
  typeof refusalSignal.get() !== 'string'
    ? revisionSignal.get()
    : undefined,
)
const details = useSignal(currentRevision)
const unbindDocument = bindPageInstanceName(
  props.context.scopes,
  HilosPages.LEGAL_DOCUMENT,
  computedSignal(() => {
    const current = currentRevision.get()
    return current ? hilosLegalDocumentLabel(current.document) : undefined
  }),
)
const unbindRevision = bindPageInstanceName(
  props.context.scopes,
  HilosPages.LEGAL_REVISION,
  computedSignal(() => {
    const current = currentRevision.get()
    if (!current) return undefined
    return current.declared && current.revision
      ? 'Revision of ' + current.revision.publishedOn
      : 'Revision ' + current.revisionId
  }),
)
onUnmounted(() => {
  unbindDocument()
  unbindRevision()
})
</script>

<template>
  <HilosAdminPage :page="page" head-kind="entity" tabs-label="Revision pages">
    <template #head-visual>
      <span
        class="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
      >
        <i class="bi bi-file-earmark-text" />
      </span>
    </template>
    <template v-if="details" #head-badges>
      <span
        v-if="details.current"
        class="badge text-bg-primary"
        data-id="legal-revision-current"
        >Current</span
      >
      <span
        v-if="details.declared && details.revision"
        class="badge text-bg-secondary"
        data-id="legal-revision-significance"
        >{{ details.revision.significance }}</span
      >
      <span v-if="!details.declared" class="badge text-bg-warning"
        >Not in code</span
      >
    </template>
    <template v-if="details" #head-description>
      <p class="text-body-secondary mb-0" data-id="legal-revision-metadata">
        {{ hilosLegalDocumentLabel(details.document)
        }}<template v-if="details.declared && details.revision">
          · Published {{ details.revision.publishedOn }} · Effective
          {{ details.revision.effectiveOn }} · Set
          {{ details.revision.setVersion }}
        </template>
      </p>
    </template>
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
      <slot />
    </template>
    <HilosSkeleton v-else />
  </HilosAdminPage>
</template>
