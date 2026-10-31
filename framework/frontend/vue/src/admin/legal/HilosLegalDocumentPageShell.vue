<script setup lang="ts">
import {
  createHilosLegalDocument,
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
const documentSignal = createHilosLegalDocument(props.context)
const document = useSignal(documentSignal)
const refusalSignal = props.context.scopes.pageDataSignal(
  LEGAL_CATALOG_REFUSAL_SECTION,
)
const refusal = useSignal(refusalSignal)
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.LEGAL_DOCUMENT,
  computedSignal(() => {
    if (
      props.context.scopes.page()?.key !== props.page ||
      typeof refusalSignal.get() === 'string'
    )
      return undefined
    const current = documentSignal.get()
    return current ? hilosLegalDocumentLabel(current.document) : undefined
  }),
)
onUnmounted(unbindName)
</script>

<template>
  <HilosAdminPage :page="page" head-kind="entity" tabs-label="Document pages">
    <template #head-visual>
      <span
        class="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
        aria-hidden="true"
      >
        <i class="bi bi-file-earmark-text" />
      </span>
    </template>
    <template v-if="document && typeof refusal !== 'string'" #head-meta>
      <code data-id="legal-document-key">{{ document.document }}</code>
    </template>
    <template v-if="document && typeof refusal !== 'string'" #head-badges>
      <span
        v-if="document.set"
        class="badge bg-body-tertiary text-body-emphasis border"
        data-id="legal-document-set-version"
      >
        Set {{ document.set.version }}
      </span>
      <span v-if="!document.declared" class="badge text-bg-warning"
        >Not in code</span
      >
    </template>
    <template
      v-if="document?.revision && typeof refusal !== 'string'"
      #head-description
    >
      <p class="text-body-secondary mb-0" data-id="legal-document-revision">
        {{ document.revision.publishedOn }} ·
        {{ document.revision.significance }} · Effective
        {{ document.revision.effectiveOn }}
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
    <template v-else-if="document"><slot /></template>
    <HilosSkeleton v-else />
  </HilosAdminPage>
</template>
